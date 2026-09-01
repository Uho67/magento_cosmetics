define([
    'uiComponent',
    'ko',
    'underscore',
    'uiRegistry',
    'Uho_NovaposhtaCheckout/js/model/np-reference-service'
], function (Component, ko, _, registry, referenceService) {
    'use strict';

    /**
     * Dependent lookup over `perspective_novaposhta_catalog_warehouse` (§2.2), filtered by the
     * indexed `city_ref` column. Disabled until a city ref exists; cleared whenever the city
     * changes. Kyiv alone has ~7,900 working warehouses, so this is always a server-side
     * search-and-limit, never a full `<select>` (§8).
     */
    return Component.extend({
        defaults: {
            template: 'Uho_NovaposhtaCheckout/checkout/shipping/np-warehouse',
            provider: 'checkoutProvider',
            dataScopePrefix: 'shippingAddress',
            searchDelay: 300
        },

        query: ko.observable(''),
        suggestions: ko.observableArray([]),
        isOpen: ko.observable(false),
        isLoading: ko.observable(false),
        selectedRef: ko.observable(''),
        selectedLabel: ko.observable(''),
        cityRef: ko.observable(''),

        /** @inheritdoc */
        initialize: function () {
            this._super();

            this.source = null;
            this.searchTimeout = null;
            this.isEnabled = ko.computed(function () {
                return !!this.cityRef();
            }, this);

            registry.async(this.provider)(function (source) {
                var cityRef,
                    ref,
                    label;

                this.source = source;
                cityRef = source.get(this.dataScopePrefix + '.extension_attributes.uho_np_city_ref') || '';
                ref = source.get(this.dataScopePrefix + '.extension_attributes.uho_np_warehouse_ref');
                label = source.get(this.dataScopePrefix + '.uho_np_warehouse_label');

                this.cityRef(cityRef);

                if (ref && cityRef) {
                    this.selectedRef(ref);
                    this.selectedLabel(label || '');
                    this.query(label || '');
                }

                source.on(this.dataScopePrefix + '.extension_attributes.uho_np_city_ref', function (newCityRef) {
                    newCityRef = newCityRef || '';

                    if (newCityRef === this.cityRef()) {
                        return;
                    }

                    this.cityRef(newCityRef);
                    this.reset();
                }.bind(this));
            }.bind(this));

            this.query.subscribe(this.onQueryChange, this);

            return this;
        },

        /**
         * Clears the selected warehouse and any pending search. Called whenever the city
         * changes, so a warehouse ref from a previous city can never survive onto a new one.
         */
        reset: function () {
            this.query('');
            this.selectedRef('');
            this.selectedLabel('');
            this.suggestions([]);
            this.isOpen(false);

            if (this.source) {
                this.source.set(this.dataScopePrefix + '.extension_attributes.uho_np_warehouse_ref', '');
                this.source.set(this.dataScopePrefix + '.uho_np_warehouse_label', '');
            }
        },

        /**
         * @param {String} value
         */
        onQueryChange: function (value) {
            if (!this.isEnabled()) {
                return;
            }

            if (this.selectedLabel() && value !== this.selectedLabel()) {
                this.selectedRef('');
                this.selectedLabel('');

                if (this.source) {
                    this.source.set(this.dataScopePrefix + '.extension_attributes.uho_np_warehouse_ref', '');
                }
            }

            if (this.searchTimeout) {
                clearTimeout(this.searchTimeout);
            }

            this.searchTimeout = setTimeout(function () {
                this.search(value);
            }.bind(this), this.searchDelay);
        },

        /**
         * @param {String} value
         */
        search: function (value) {
            if (!this.cityRef()) {
                return;
            }

            this.isLoading(true);

            referenceService.searchWarehouses(this.cityRef(), value).done(function (result) {
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

        openDropdown: function () {
            if (!this.isEnabled()) {
                return;
            }

            if (this.suggestions().length) {
                this.isOpen(true);
            } else {
                this.search(this.query());
            }
        },

        /**
         * @param {Object} suggestion
         */
        selectWarehouse: function (suggestion) {
            this.selectedRef(suggestion.ref);
            this.selectedLabel(suggestion.label);
            this.query(suggestion.label);
            this.isOpen(false);
            this.suggestions([]);

            if (this.source) {
                this.source.set(
                    this.dataScopePrefix + '.extension_attributes.uho_np_warehouse_ref',
                    suggestion.ref
                );
                this.source.set(this.dataScopePrefix + '.uho_np_warehouse_label', suggestion.label);
            }
        },

        /**
         * Delayed so a click on a suggestion registers before the list is hidden by blur.
         */
        closeDropdown: function () {
            _.delay(function () {
                this.isOpen(false);
            }.bind(this), 200);
        }
    });
});
