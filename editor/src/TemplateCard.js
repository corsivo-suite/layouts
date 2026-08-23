import { BlockPreview } from '@wordpress/block-editor';
import { createBlocksFromInnerBlocksTemplate } from '@wordpress/blocks';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { getMissingBlockTypes } from './templateBlocks';

export default function TemplateCard( {
	template,
	disabled,
	isSelected,
	onChoose,
} ) {
	const [ imageFailed, setImageFailed ] = useState( false );
	const [ isPreviewVisible, setPreviewVisible ] = useState( false );
	const [ , setAvailabilityRevision ] = useState( 0 );
	const previewElement = useRef( null );
	const preview = ( () => {
		const missingBlockTypes = getMissingBlockTypes( template.blocks );

		if ( missingBlockTypes.length || ! isPreviewVisible ) {
			return {
				blocks: [],
				missingBlockTypes,
				failed: false,
				deferred: ! isPreviewVisible,
				protected: false,
			};
		}

		if ( template.preview_disabled ) {
			return {
				blocks: [],
				missingBlockTypes: [],
				failed: false,
				deferred: false,
				protected: true,
			};
		}

		try {
			return {
				blocks: createBlocksFromInnerBlocksTemplate( template.blocks ),
				missingBlockTypes: [],
				failed: false,
				deferred: false,
				protected: false,
			};
		} catch {
			return {
				blocks: [],
				missingBlockTypes: [],
				failed: true,
				deferred: false,
				protected: false,
			};
		}
	} )();
	const isUnavailable =
		preview.failed || preview.missingBlockTypes.length > 0;
	const unavailableMessage = preview.missingBlockTypes.length
		? preview.missingBlockTypes.join( ', ' )
		: __( 'Anteprima non disponibile', 'corsivo-layouts' );
	const statusId = `corsivo-layouts-${ template.id }-status`;
	let previewContent;

	useEffect( () => {
		setImageFailed( false );
	}, [ template.image ] );

	useEffect( () => {
		const element = previewElement.current;

		if ( ! element || typeof window.IntersectionObserver !== 'function' ) {
			setPreviewVisible( true );
			return undefined;
		}

		const observer = new window.IntersectionObserver(
			( entries ) => {
				if ( entries.some( ( entry ) => entry.isIntersecting ) ) {
					setPreviewVisible( true );
					observer.disconnect();
				}
			},
			{ rootMargin: '200px' }
		);

		observer.observe( element );

		return () => observer.disconnect();
	}, [] );

	const refreshAvailability = () => {
		setAvailabilityRevision( ( revision ) => revision + 1 );
	};

	const chooseTemplate = ( event ) => {
		if ( getMissingBlockTypes( template.blocks ).length ) {
			refreshAvailability();
			return;
		}

		onChoose( event );
	};

	if ( isUnavailable ) {
		previewContent = (
			<span className="corsivo-layouts-card-placeholder">
				{ __( 'Non disponibile', 'corsivo-layouts' ) }
			</span>
		);
	} else if ( template.image && ! imageFailed ) {
		previewContent = (
			<img
				src={ template.image }
				alt=""
				loading="lazy"
				decoding="async"
				referrerPolicy="no-referrer"
				onError={ () => setImageFailed( true ) }
			/>
		);
	} else if ( preview.protected ) {
		previewContent = (
			<span className="corsivo-layouts-card-placeholder">
				{ __( 'Anteprima protetta', 'corsivo-layouts' ) }
			</span>
		);
	} else if ( preview.deferred ) {
		previewContent = null;
	} else {
		previewContent = (
			<BlockPreview blocks={ preview.blocks } viewportWidth={ 1200 } />
		);
	}

	return (
		<button
			type="button"
			className={ `corsivo-layouts-card${
				isSelected ? ' is-selected' : ''
			}${ isUnavailable ? ' is-unavailable' : '' }` }
			disabled={ disabled }
			aria-disabled={ disabled || isUnavailable }
			aria-pressed={ isSelected }
			aria-describedby={ isUnavailable ? statusId : undefined }
			onFocus={ refreshAvailability }
			onClick={ chooseTemplate }
		>
			<span
				ref={ previewElement }
				className="corsivo-layouts-card-preview"
				aria-hidden="true"
			>
				{ previewContent }
			</span>
			<span className="corsivo-layouts-card-name">{ template.name }</span>
			{ template.description && (
				<span className="corsivo-layouts-card-description">
					{ template.description }
				</span>
			) }
			{ isUnavailable && (
				<span id={ statusId } className="corsivo-layouts-card-status">
					{ preview.missingBlockTypes.length > 0 && (
						<>
							{ __(
								'Blocchi non disponibili:',
								'corsivo-layouts'
							) }{ ' ' }
						</>
					) }
					{ unavailableMessage }
				</span>
			) }
		</button>
	);
}
