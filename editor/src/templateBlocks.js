import { getBlockType } from '@wordpress/blocks';

export function getMissingBlockTypes( blockTemplates ) {
	const missingBlockTypes = new Set();

	const inspectBlocks = ( blocks ) => {
		if ( ! Array.isArray( blocks ) ) {
			return;
		}

		blocks.forEach( ( block ) => {
			if ( ! Array.isArray( block ) || typeof block[ 0 ] !== 'string' ) {
				return;
			}

			if ( ! getBlockType( block[ 0 ] ) ) {
				missingBlockTypes.add( block[ 0 ] );
			}

			inspectBlocks( block[ 2 ] );
		} );
	};

	inspectBlocks( blockTemplates );

	return [ ...missingBlockTypes ];
}
