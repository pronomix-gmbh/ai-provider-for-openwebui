( function () {
	'use strict';

	var apiFetch = window.wp && window.wp.apiFetch ? window.wp.apiFetch : null;
	var i18n = window.wp && window.wp.i18n ? window.wp.i18n : null;

	var __ = i18n && i18n.__ ? i18n.__ : function ( text ) {
		return text;
	};
	var sprintf = i18n && i18n.sprintf ? i18n.sprintf : function ( text ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var nextArgIndex = 0;

		text = text.replace( /%(\d+)\$[sd]/g, function ( match, position ) {
			var index = Number( position ) - 1;
			return typeof args[ index ] !== 'undefined' ? String( args[ index ] ) : match;
		} );

		return text.replace( /%[sd]/g, function ( match ) {
			var value = args[ nextArgIndex ];
			nextArgIndex += 1;
			return typeof value !== 'undefined' ? String( value ) : match;
		} );
	};

	document.addEventListener( 'DOMContentLoaded', function () {
		var settings = window.aiProviderForOpenWebUISettings;
		var status = document.getElementById( 'openwebui-model-status' );
		var modelFieldConfigs = settings && Array.isArray( settings.modelFields ) ? settings.modelFields : [];
		var modelFields = [];

		if ( ! settings || ! apiFetch || ! status || modelFieldConfigs.length === 0 ) {
			return;
		}

		modelFieldConfigs.forEach( function ( config ) {
			if ( ! config || typeof config.fieldId !== 'string' || typeof config.modelSuggestionsId !== 'string' ) {
				return;
			}

			var fieldEl = document.getElementById( config.fieldId );
			var suggestionsEl = document.getElementById( config.modelSuggestionsId );
			if ( ! fieldEl || ! suggestionsEl ) {
				return;
			}

			modelFields.push( {
				field: fieldEl,
				suggestions: suggestionsEl,
				capability: typeof config.capability === 'string' ? config.capability : 'any',
			} );
		} );

		if ( modelFields.length === 0 ) {
			return;
		}

		function setStatus( message, isError ) {
			status.textContent = message;
			status.className = isError ? 'error' : '';
		}

		function appendSuggestion( listEl, value, label ) {
			var option = document.createElement( 'option' );
			option.value = value;
			option.label = label;
			listEl.appendChild( option );
		}

		function getLabelForModel( model ) {
			if ( typeof model.name === 'string' && model.name.length > 0 && model.name !== model.id ) {
				return model.name + ' (' + model.id + ')';
			}
			return model.id;
		}

		function getSupportedCapabilities( model ) {
			if ( ! model || ! Array.isArray( model.supportedCapabilities ) ) {
				return [];
			}

			return model.supportedCapabilities.filter( function ( capability ) {
				return typeof capability === 'string' && capability.length > 0;
			} );
		}

		function modelSupportsVision( model ) {
			if ( ! model || ! Array.isArray( model.supportedOptions ) ) {
				return false;
			}

			var inputModalitiesOption = model.supportedOptions.find( function ( option ) {
				return option && option.name === 'inputModalities';
			} );

			if ( ! inputModalitiesOption || ! Array.isArray( inputModalitiesOption.supportedValues ) ) {
				return false;
			}

			return inputModalitiesOption.supportedValues.some( function ( modalityGroup ) {
				if ( ! Array.isArray( modalityGroup ) ) {
					return false;
				}

				return modalityGroup.some( function ( modality ) {
					return modality === 'image';
				} );
			} );
		}

		function modelSupportsCapability( model, capability ) {
			if ( capability === 'any' ) {
				return true;
			}

			if ( capability === 'vision' ) {
				return modelSupportsVision( model );
			}

			var capabilities = getSupportedCapabilities( model );
			return capabilities.indexOf( capability ) !== -1;
		}

		function populateModelSuggestions( models ) {
			var validModels = models.filter( function ( model ) {
				return model && typeof model.id === 'string' && model.id.length > 0;
			} );

			var stats = {
				all: validModels.length,
				text: 0,
				image: 0,
				vision: 0,
			};

			validModels.forEach( function ( model ) {
				if ( modelSupportsCapability( model, 'text_generation' ) ) {
					stats.text += 1;
				}
				if ( modelSupportsCapability( model, 'image_generation' ) ) {
					stats.image += 1;
				}
				if ( modelSupportsCapability( model, 'vision' ) ) {
					stats.vision += 1;
				}
			} );

			modelFields.forEach( function ( modelField ) {
				var filteredModels = validModels.filter( function ( model ) {
					return modelSupportsCapability( model, modelField.capability );
				} );

				if ( filteredModels.length === 0 && modelField.capability !== 'any' ) {
					filteredModels = validModels;
				}

				modelField.suggestions.innerHTML = '';
				filteredModels.forEach( function ( model ) {
					appendSuggestion( modelField.suggestions, model.id, getLabelForModel( model ) );
				} );

				modelField.field.disabled = false;
			} );

			setStatus(
				sprintf(
					__( '%1$d models loaded (Text: %2$d, Image: %3$d, Vision: %4$d).', 'ai-provider-for-open-webui' ),
					stats.all,
					stats.text,
					stats.image,
					stats.vision
				),
				false
			);
		}

		modelFields.forEach( function ( modelField ) {
			modelField.field.disabled = true;
		} );
		setStatus( __( 'Loading models...', 'ai-provider-for-open-webui' ), false );

		apiFetch( { url: settings.ajaxUrl } )
			.then( function ( response ) {
				if ( ! response || ! response.success || ! Array.isArray( response.data ) ) {
					setStatus(
						( response && typeof response.data === 'string' )
							? response.data
							: __( 'Failed to load models. You can still type model IDs manually.', 'ai-provider-for-open-webui' ),
						true
					);
					modelFields.forEach( function ( modelField ) {
						modelField.field.disabled = false;
					} );
					return;
				}

				populateModelSuggestions( response.data );
			} )
			.catch( function ( error ) {
				var fallback = __( 'Could not connect to load models. You can still type model IDs manually.', 'ai-provider-for-open-webui' );
				setStatus(
					error && typeof error.message === 'string' ? error.message : fallback,
					true
				);
				modelFields.forEach( function ( modelField ) {
					modelField.field.disabled = false;
				} );
			} );
	} );
}() );
