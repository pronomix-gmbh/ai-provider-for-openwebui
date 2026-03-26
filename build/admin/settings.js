( function () {
	'use strict';

	var apiFetch = window.wp && window.wp.apiFetch ? window.wp.apiFetch : null;
	var i18n = window.wp && window.wp.i18n ? window.wp.i18n : null;

	var __ = i18n && i18n.__ ? i18n.__ : function ( text ) {
		return text;
	};
	var _n = i18n && i18n._n ? i18n._n : function ( single, plural, number ) {
		return number === 1 ? single : plural;
	};
	var sprintf = i18n && i18n.sprintf ? i18n.sprintf : function ( text, value ) {
		return text.replace( '%s', String( value ) ).replace( '%d', String( value ) );
	};

	document.addEventListener( 'DOMContentLoaded', function () {
		var settings = window.aiProviderForOpenWebUISettings;
		var status = document.getElementById( 'openwebui-model-status' );
		var modelField = document.getElementById( settings && settings.modelFieldId ? settings.modelFieldId : '' );
		var suggestions = document.getElementById( settings && settings.modelSuggestionsId ? settings.modelSuggestionsId : '' );

		if ( ! settings || ! apiFetch || ! status || ! modelField || ! suggestions ) {
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

		function populateModelSuggestions( models ) {
			var modelCount = 0;
			var selectedModel = modelField.value || settings.selectedModel || '';
			var hasSelected = false;

			suggestions.innerHTML = '';

			models.forEach( function ( model ) {
				if ( ! model || typeof model.id !== 'string' || model.id.length === 0 ) {
					return;
				}

				modelCount += 1;
				if ( selectedModel === model.id ) {
					hasSelected = true;
				}

				if ( typeof model.name === 'string' && model.name.length > 0 && model.name !== model.id ) {
					appendSuggestion( suggestions, model.id, model.name + ' (' + model.id + ')' );
					return;
				}

				appendSuggestion( suggestions, model.id, model.id );
			} );

			if ( selectedModel && ! hasSelected ) {
				setStatus(
					sprintf(
						__( 'Current selection is not in fetched list: %s. You can keep it or change it manually.', 'ai-provider-for-open-webui' ),
						selectedModel
					),
					false
				);
				modelField.disabled = false;
				return;
			}

			setStatus(
				sprintf(
					_n(
						'%d model loaded. Start typing to choose a preferred model.',
						'%d models loaded. Start typing to choose a preferred model.',
						modelCount,
						'ai-provider-for-open-webui'
					),
					modelCount
				),
				false
			);

			modelField.disabled = false;
		}

		modelField.disabled = true;
		setStatus( __( 'Loading models...', 'ai-provider-for-open-webui' ), false );

		apiFetch( { url: settings.ajaxUrl } )
			.then( function ( response ) {
				if ( ! response || ! response.success || ! Array.isArray( response.data ) ) {
					setStatus(
						( response && typeof response.data === 'string' )
							? response.data
							: __( 'Failed to load models. You can still type a model ID manually.', 'ai-provider-for-open-webui' ),
						true
					);
					modelField.disabled = false;
					return;
				}

				populateModelSuggestions( response.data );
			} )
			.catch( function ( error ) {
				var fallback = __( 'Could not connect to load models. You can still type a model ID manually.', 'ai-provider-for-open-webui' );
				setStatus(
					error && typeof error.message === 'string' ? error.message : fallback,
					true
				);
				modelField.disabled = false;
			} );
	} );
}() );
