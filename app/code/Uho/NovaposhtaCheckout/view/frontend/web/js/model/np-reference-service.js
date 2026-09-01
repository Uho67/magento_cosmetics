define([
    'jquery'
], function ($) {
    'use strict';

    /**
     * Thin AJAX client for the two frontend-only lookup endpoints
     * (`Controller/Ajax/CitySearch`, `Controller/Ajax/WarehouseList`). Never calls
     * api.novaposhta.ua — both routes read only the locally cron-synced catalog tables.
     */
    var cityUrl = window.BASE_URL + 'uho_novaposhta/ajax/citysearch',
        warehouseUrl = window.BASE_URL + 'uho_novaposhta/ajax/warehouselist',
        pendingCityRequest = null,
        pendingWarehouseRequest = null;

    return {
        /**
         * @param {String} query
         * @return {jQuery.jqXHR}
         */
        searchCities: function (query) {
            if (pendingCityRequest) {
                pendingCityRequest.abort();
            }

            pendingCityRequest = $.getJSON(cityUrl, {
                q: query
            });

            return pendingCityRequest;
        },

        /**
         * @param {String} cityRef
         * @param {String} query
         * @return {jQuery.jqXHR}
         */
        searchWarehouses: function (cityRef, query) {
            if (pendingWarehouseRequest) {
                pendingWarehouseRequest.abort();
            }

            pendingWarehouseRequest = $.getJSON(warehouseUrl, {
                cityRef: cityRef,
                q: query || ''
            });

            return pendingWarehouseRequest;
        }
    };
});
