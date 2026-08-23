import {
	createBlocksFromInnerBlocksTemplate,
	serialize,
} from '@wordpress/blocks';
import { select, useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

import { getMissingBlockTypes } from './templateBlocks';

const APPLIED_NOTICE_ID = 'corsivo-layouts-applied';

function hasPageTemplate( template ) {
	return Object.prototype.hasOwnProperty.call( template, 'page_template' );
}

function editorIsBusy( editor ) {
	return (
		editor.isSavingPost() ||
		( typeof editor.isPostLocked === 'function' && editor.isPostLocked() )
	);
}

function canReplaceBlocks( blockEditor, currentBlocks, replacementBlocks ) {
	if ( blockEditor.getTemplateLock() ) {
		return false;
	}

	const currentBlockIds = currentBlocks.map( ( block ) => block.clientId );

	if (
		currentBlockIds.length &&
		! blockEditor.canRemoveBlocks( currentBlockIds )
	) {
		return false;
	}

	return replacementBlocks.every( ( block ) =>
		blockEditor.canInsertBlockType( block.name )
	);
}

function applyEdits( editPost, edits, applicationState ) {
	try {
		editPost( edits );
		return applicationState;
	} catch {
		return null;
	}
}

function samePost( post, applicationState ) {
	return (
		post &&
		post.id === applicationState.postId &&
		post.type === applicationState.postType
	);
}

export default function useApplyTemplate() {
	const { editPost } = useDispatch( 'core/editor' );
	const {
		createErrorNotice,
		createSuccessNotice,
		createWarningNotice,
		removeNotice,
	} = useDispatch( 'core/notices' );

	const showBusyNotice = () =>
		createWarningNotice(
			__(
				'Attendi la fine del salvataggio prima di cambiare layout.',
				'corsivo-layouts'
			),
			{ type: 'snackbar' }
		);

	const showLockedNotice = () =>
		createWarningNotice(
			__(
				'La struttura del contenuto è bloccata e non può essere sostituita.',
				'corsivo-layouts'
			),
			{ type: 'snackbar' }
		);

	return ( template ) => {
		const missingBlockTypes = getMissingBlockTypes( template.blocks );

		if ( missingBlockTypes.length ) {
			createErrorNotice(
				__(
					'Impossibile applicare il layout: uno o più blocchi non sono disponibili.',
					'corsivo-layouts'
				),
				{ type: 'snackbar' }
			);
			return false;
		}

		const editor = select( 'core/editor' );

		if ( editorIsBusy( editor ) ) {
			showBusyNotice();
			return false;
		}

		let nextBlocks;

		try {
			nextBlocks = createBlocksFromInnerBlocksTemplate( template.blocks );
		} catch {
			createErrorNotice(
				__(
					'Il layout non è valido e non è stato applicato.',
					'corsivo-layouts'
				),
				{ type: 'snackbar' }
			);
			return false;
		}

		const blockEditor = select( 'core/block-editor' );
		const previousBlocks = blockEditor.getBlocks();

		if ( ! canReplaceBlocks( blockEditor, previousBlocks, nextBlocks ) ) {
			showLockedNotice();
			return false;
		}

		const currentPost = editor.getCurrentPost();

		if ( ! currentPost ) {
			createErrorNotice(
				__( 'Il layout non è stato applicato.', 'corsivo-layouts' ),
				{ type: 'snackbar' }
			);
			return false;
		}

		let nextContent;
		let previousContent;

		try {
			nextContent = serialize( nextBlocks );
			previousContent = serialize( previousBlocks );
		} catch {
			createErrorNotice(
				__( 'Il layout non è stato applicato.', 'corsivo-layouts' ),
				{ type: 'snackbar' }
			);
			return false;
		}

		const pageTemplateChanges = hasPageTemplate( template );
		const previousPageTemplate = pageTemplateChanges
			? editor.getEditedPostAttribute( 'template' ) || ''
			: '';
		const nextEdits = {
			blocks: nextBlocks,
			content: nextContent,
		};

		if ( pageTemplateChanges ) {
			nextEdits.template = template.page_template;
		}

		const applicationState = applyEdits( editPost, nextEdits, {
			postId: currentPost.id,
			postType: currentPost.type,
			previousBlocks,
			previousContent,
			previousPageTemplate,
		} );

		if ( ! applicationState ) {
			createErrorNotice(
				__( 'Il layout non è stato applicato.', 'corsivo-layouts' ),
				{ type: 'snackbar' }
			);
			return false;
		}

		const undoApplication = () => {
			const currentEditor = select( 'core/editor' );

			if (
				! samePost( currentEditor.getCurrentPost(), applicationState )
			) {
				createWarningNotice(
					__(
						'Il contenuto aperto è cambiato e il layout non può essere annullato qui.',
						'corsivo-layouts'
					),
					{ type: 'snackbar' }
				);
				return;
			}

			if ( editorIsBusy( currentEditor ) ) {
				showBusyNotice();
				return;
			}

			const currentBlockEditor = select( 'core/block-editor' );
			const currentBlocks = currentBlockEditor.getBlocks();
			let currentContent;

			try {
				currentContent = serialize( currentBlocks );
			} catch {
				createErrorNotice(
					__(
						'Il layout non può essere annullato.',
						'corsivo-layouts'
					),
					{ type: 'snackbar' }
				);
				return;
			}

			const currentPageTemplate = pageTemplateChanges
				? currentEditor.getEditedPostAttribute( 'template' ) || ''
				: '';

			if (
				currentContent === applicationState.previousContent &&
				currentPageTemplate === applicationState.previousPageTemplate
			) {
				removeNotice( APPLIED_NOTICE_ID );
				return;
			}

			if (
				currentContent !== nextContent ||
				currentPageTemplate !== ( template.page_template || '' )
			) {
				createWarningNotice(
					__(
						'Il contenuto è già cambiato. Usa la cronologia dell’editor per annullare.',
						'corsivo-layouts'
					),
					{ type: 'snackbar' }
				);
				return;
			}

			if (
				! canReplaceBlocks(
					currentBlockEditor,
					currentBlocks,
					applicationState.previousBlocks
				)
			) {
				showLockedNotice();
				return;
			}

			const previousEdits = {
				blocks: applicationState.previousBlocks,
				content: applicationState.previousContent,
			};

			if ( pageTemplateChanges ) {
				previousEdits.template = applicationState.previousPageTemplate;
			}

			try {
				editPost( previousEdits );
				removeNotice( APPLIED_NOTICE_ID );
			} catch {
				createErrorNotice(
					__(
						'Il layout non può essere annullato.',
						'corsivo-layouts'
					),
					{ type: 'snackbar' }
				);
			}
		};

		createSuccessNotice( __( 'Layout applicato.', 'corsivo-layouts' ), {
			explicitDismiss: true,
			id: APPLIED_NOTICE_ID,
			type: 'snackbar',
			actions: [
				{
					label: __( 'Annulla', 'corsivo-layouts' ),
					onClick: undoApplication,
				},
			],
		} );

		return true;
	};
}
