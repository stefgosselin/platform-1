/*jslint nomen:true*/
/*global define*/
define([
    'jquery',
    'underscore',
    'oroui/js/tools',
    './abstract-filter'
], function ($, _, tools, AbstractFilter) {
    'use strict';

    var EmptyFilter;

    /**
     * @export  oro/filter/empty-filter
     * @class   oro.filter.EmptyFilter
     * @extends oro.filter.AbstractFilter
     */
    EmptyFilter = AbstractFilter.extend({

        /**
         * Template selector for filter criteria
         *
         * @property
         */
        emptyOption: 'filter_empty_option',

        /**
         * Template selector for filter criteria
         *
         * @property
         */
        notEmptyOption: 'filter_not_empty_option',

        /**
         * Stores old value for empty filter
         *
         * @property
         */
        query: null,

        /**
         * Marks value to revert
         *
         * @property
         */
        revertQuery: false,

        /**
         * @property
         */
        updateSelector: '.filter-update',

        /**
         * @property
         */
        updateSelectorEmptyClass: 'filter-update-empty',

        /**
         * @property {String}
         */
        caret: '<span class="caret"></span>',

        initialize: function (options) {
            var opts = _.pick(options || {}, 'caret');
            _.extend(this, opts);

            EmptyFilter.__super__.initialize.apply(this, arguments);
        },

        /**
         * Set raw value to filter
         *
         * @param value
         * @return {*}
         */
        setValue: function (value) {
            var oldValue = this.value;
            this.value = tools.deepClone(value);
            this._updateDOMValue();
            this._onValueUpdated(this.value, oldValue);

            return this;
        },

        /**
         * Open/close select dropdown
         *
         * @param {Event} e
         * @protected
         */
        _onClickChoiceValue: function (e) {
            $(e.currentTarget).parent().parent().find('li').each(function () {
                $(this).removeClass('active');
            });
            $(e.currentTarget).parent().addClass('active');

            var parentDiv = $(e.currentTarget).parent().parent().parent();
            var type = $(e.currentTarget).attr('data-value');
            var choiceName = $(e.currentTarget).html();

            var criteriaValues = this.$(this.criteriaValueSelectors.type).val(type);
            this.fixSelects();
            criteriaValues.trigger('change');
            choiceName += this.caret;
            parentDiv.find('.dropdown-toggle').html(choiceName);

            this._handleEmptyFilter(type);

            e.preventDefault();
        },

        /**
         * Without this $select.val() or select.selectedValue returns wrong value
         * (tested with select.ui-datepicker-month)
         */
        fixSelects: function () {
            this.$('select').each(function () {
                var $select = $(this);
                if ($select.val()) {
                    return true;
                }

                $select.val($select.find('option[selected]').val());
            });
        },

        /**
         * Handle click on criteria selector
         *
         * @param {Event} e
         * @protected
         */
        _onClickCriteriaSelector: function (e) {
            e.stopPropagation();
            $('body').trigger('click');
            if (!this.popupCriteriaShowed) {
                this._showCriteria();
            } else {
                this._hideCriteria();
            }

            this._handleEmptyFilter();
        },

        /**
         * Handle empty filter selection
         *
         * @protected
         */
        _handleEmptyFilter: function () {
            var container = this.$(this.criteriaSelector);
            var item = container.find(this.criteriaValueSelectors.value);
            var type = container.find(this.criteriaValueSelectors.type).val();
            var button = container.find(this.updateSelector);

            if (this.isEmptyType(type)) {
                var query = item.val();
                if (!this.isEmptyType(query)) {
                    this.query = query;
                    this.revertQuery = true;
                }

                item.hide().val(type);
                button.addClass(this.updateSelectorEmptyClass);

                return;
            }

            if (this.revertQuery) {
                item.val(this.query);

                this.query = null;
                this.revertQuery = false;
            }

            button.removeClass(this.updateSelectorEmptyClass);
            item.show();
        },

        /**
         * @inheritDoc
         */
        isEmptyValue: function () {
            if (this.isEmptyType(this.value.type)) {
                return false;
            }

            if (_.has(this.emptyValue, 'value') && _.has(this.value, 'value')) {
                return tools.isEqualsLoosely(this.value.value, this.emptyValue.value);
            }
            return true;
        },

        /**
         * @param {String} type
         * @returns {Boolean}
         */
        isEmptyType: function(type) {
            return _.contains([this.emptyOption, this.notEmptyOption], type);
        }
    });

    return EmptyFilter;
});
