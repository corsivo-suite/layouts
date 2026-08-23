import { registerPlugin } from '@wordpress/plugins';
import { PluginPostStatusInfo } from '@wordpress/editor';
import { Button } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import TemplatesModal from './TemplatesModal';
import '@corsivo-suite/wp-admin-ui/styles.css';
import './editor.scss';

const configuration = window.corsivoLayoutsConfig || {};
const templates = Array.isArray( configuration.templates )
	? configuration.templates
	: [];

function CorsivoLayouts() {
	const { isCleanNewPost, isSavingPost, isStructureLocked } = useSelect(
		( select ) => {
			const blockEditor = select( 'core/block-editor' );
			const currentBlockIds = blockEditor
				.getBlocks()
				.map( ( block ) => block.clientId );

			return {
				isCleanNewPost: select( 'core/editor' ).isCleanNewPost(),
				isSavingPost: select( 'core/editor' ).isSavingPost(),
				isStructureLocked:
					Boolean( blockEditor.getTemplateLock() ) ||
					( currentBlockIds.length > 0 &&
						! blockEditor.canRemoveBlocks( currentBlockIds ) ),
			};
		},
		[]
	);
	const [ isPickerOpen, setPickerOpen ] = useState( false );
	const didAutoOpen = useRef( false );

	useEffect( () => {
		if (
			! didAutoOpen.current &&
			isCleanNewPost &&
			configuration.openOnNewPost === true
		) {
			didAutoOpen.current = true;
			setPickerOpen( true );
		}
	}, [ isCleanNewPost ] );

	if ( ! templates.length || isStructureLocked ) {
		return null;
	}

	return (
		<>
			<PluginPostStatusInfo>
				<Button
					variant="secondary"
					className="corsivo-layouts-open"
					disabled={ isSavingPost }
					onClick={ () => setPickerOpen( true ) }
				>
					{ __( 'Scegli un layout', 'corsivo-layouts' ) }
				</Button>
			</PluginPostStatusInfo>

			{ isPickerOpen && (
				<TemplatesModal
					templates={ templates }
					onClose={ () => setPickerOpen( false ) }
				/>
			) }
		</>
	);
}

registerPlugin( 'corsivo-layouts', {
	render: CorsivoLayouts,
} );
