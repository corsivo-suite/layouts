import { ConfirmDialog } from '@corsivo-suite/wp-admin-ui/confirm-dialog';
import { Modal } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import TemplateCard from './TemplateCard';
import useApplyTemplate from './useApplyTemplate';

export default function TemplatesModal( { templates, onClose } ) {
	const { isEditedPostEmpty, isSavingPost } = useSelect(
		( select ) => ( {
			isEditedPostEmpty: select( 'core/editor' ).isEditedPostEmpty(),
			isSavingPost: select( 'core/editor' ).isSavingPost(),
		} ),
		[]
	);
	const [ pendingTemplate, setPendingTemplate ] = useState( null );
	const confirmationTrigger = useRef( null );
	const applyTemplate = useApplyTemplate();

	const applyAndClose = ( template ) => {
		if ( applyTemplate( template ) ) {
			setPendingTemplate( null );
			onClose();
		}
	};

	const chooseTemplate = ( template, trigger ) => {
		if ( isEditedPostEmpty ) {
			applyAndClose( template );
			return;
		}

		confirmationTrigger.current = trigger;
		setPendingTemplate( template );
	};

	const cancelConfirmation = () => {
		const trigger = confirmationTrigger.current;

		setPendingTemplate( null );
		window.requestAnimationFrame( () => trigger?.focus() );
	};

	return (
		<Modal
			title={ __( 'Layout del contenuto', 'corsivo-layouts' ) }
			size="large"
			className="corsivo-layouts-modal"
			onRequestClose={ onClose }
		>
			<ConfirmDialog
				isOpen={ Boolean( pendingTemplate ) }
				title={ pendingTemplate?.name }
				confirmLabel={ __( 'Applica il layout', 'corsivo-layouts' ) }
				cancelLabel={ __( 'Mantieni il contenuto', 'corsivo-layouts' ) }
				isDestructive
				busy={ isSavingPost }
				onCancel={ cancelConfirmation }
				onConfirm={ () => applyAndClose( pendingTemplate ) }
			>
				{ __(
					'Sostituirà tutti i blocchi presenti. Vuoi continuare?',
					'corsivo-layouts'
				) }
			</ConfirmDialog>

			<div className="corsivo-layouts-grid">
				{ templates.map( ( template ) => (
					<TemplateCard
						key={ template.id }
						template={ template }
						disabled={ isSavingPost }
						isSelected={ pendingTemplate?.id === template.id }
						onChoose={ ( event ) =>
							chooseTemplate( template, event.currentTarget )
						}
					/>
				) ) }
			</div>
		</Modal>
	);
}
