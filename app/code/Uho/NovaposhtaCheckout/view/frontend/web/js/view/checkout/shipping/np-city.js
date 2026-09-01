define([
    'uiComponent',
    'ko',
    'underscore',
    'uiRegistry',
    'Uho_NovaposhtaCheckout/js/model/np-reference-service'
], function (Component, ko, _, registry, referenceService) {
    'use strict';

    /**
     * Typeahead over `perspective_novaposhta_catalog_cities` (§2.1). Writes only the chosen
     * city's `ref` into `shippingAddress.extension_attributes.uho_np_city_ref` — the storefront
     * never composes street/region/postcode itself (§B3); `Model\Address\Composer` does that
     * server-side from the ref alone.
     */
    return Component.extend({
        defaults: {
            template: 'Uho_NovaposhtaCheckout/checkout/shipping/np-city',
            provider: 'checkoutProvider',
            dataScopePrefix: 'shippingAddress',
            minQueryLength: 2,
            searchDelay: 300
        },

        query: ko.observable(''),
        suggestions: ko.observableArray([]),
        isOpen: ko.observable(false),
        isLoading: ko.observable(false),
        selectedRef: ko.observable(''),
        selectedLabel: ko.observable(''),

        /** @inheritdoc */
        initialize: function () {
            this._super();

            this.source = null;
            this.searchTimeout = null;

            registry.async(this.provider)(function (source) {
                var ref,
                    label;

                this.source = source;
                ref = source.get(this.dataScopePrefix + '.extension_attributes.uho_np_city_ref');
                label = source.get(this.dataScopePrefix + '.uho_np_city_label');

                if (ref) {
                    this.selectedRef(ref);
                    this.selectedLabel(label || '');
                    this.query(label || '');
                }
            }.bind(this));

            this.query.subscribe(this.onQueryChange, this);

            return this;
        },

        /**
         * @param {String} value
         */
        onQueryChange: function (value) {
            if (this.selectedLabel() && value !== this.selectedLabel()) {
                this.clearSelection();
            }

            if (this.searchTimeout) {
                clearTimeout(this.searchTimeout);
            }

            if (!value || value.length < this.minQueryLength) {
                this.suggestions([]);
                this.isOpen(false);

                return;
            }

            this.searchTimeout = setTimeout(function () {
                this.search(value);
            }.bind(this), this.searchDelay);
        },

        /**
         * @param {String} value
         */
        search: function (value) {
            this.isLoading(true);

            referenceService.searchCities(value).done(function (result) {
                this.suggestions(result || []);
                this.isOpen(true);
            }.bind(this)).fail(function (jqXHR) {
                if (jqXHR.statusText !== 'abort') {
                    this.suggestions([]);
                }
            }.bind(this)).always(function () {
                this.isLoading(false);
            }.bind(this));
        },

        /**
         * @param {Object} suggestion
         */
        selectCity: function (suggestion) {
            this.selectedRef(suggestion.ref);
            this.selectedLabel(suggestion.label);
            this.query(suggestion.label);
            this.isOpen(false);
            this.suggestions([]);

            if (this.source) {
                this.source.set(this.dataScopePrefix + '.extension_attributes.uho_np_city_ref', suggestion.ref);
                this.source.set(this.dataScopePrefix + '.uho_np_city_label', suggestion.label);
            }
        },

        clearSelection: function () {
            this.selectedRef('');
            this.selectedLabel('');

            if (this.source) {
                this.source.set(this.dataScopePrefix + '.extension_attributes.uho_np_city_ref', '');
                this.source.set(this.dataScopePrefix + '.uho_np_city_label', '');
            }
        },

        /**
         * Delayed so a click on a suggestion registers before the list is hidden by blur.
         */
        closeDropdown: function () {
            _.delay(function () {
                this.isOpen(false);
            }.bind(this), 200);
        },

        openDropdownIfHasSuggestions: function () {
            if (this.suggestions().length) {
                this.isOpen(true);
            }
        }
    });
});
