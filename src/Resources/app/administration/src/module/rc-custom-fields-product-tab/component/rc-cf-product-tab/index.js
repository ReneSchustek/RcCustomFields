import template from './rc-cf-product-tab.html.twig';

const { Component, Mixin } = Shopware;

const FIELD_COUNT = 10;
const FIELD_TYPES = ['text', 'number', 'textarea', 'date', 'time', 'datetime', 'checkbox', 'select'];

Component.register('rc-cf-product-tab', {
    template,

    inject: ['repositoryFactory'],

    mixins: [
        Mixin.getByName('notification'),
    ],

    data() {
        return {
            product: null,
            isLoading: false,
            isSaving: false,
        };
    },

    computed: {
        productRepository() {
            return this.repositoryFactory.create('product');
        },

        productId() {
            return this.$route.params.id;
        },

        fieldIndices() {
            return Array.from({ length: FIELD_COUNT }, (_, i) => i + 1);
        },

        fieldTypes() {
            return FIELD_TYPES.map((t) => ({
                value: t,
                label: this.$tc('rcCustomFields.productTab.type.' + t),
            }));
        },

        activeFieldsForPreview() {
            if (!this.product || !this.product.customFields) {
                return [];
            }
            const cf = this.product.customFields;
            const fields = [];
            this.fieldIndices.forEach((i) => {
                if (cf['rc_custom_field_' + i + '_active']) {
                    fields.push({
                        index: i,
                        label: cf['rc_custom_field_' + i + '_label'] || ('Feld ' + i),
                        type: cf['rc_custom_field_' + i + '_type'] || 'text',
                        required: !!cf['rc_custom_field_' + i + '_required'],
                    });
                }
            });
            return fields;
        },
    },

    created() {
        this.loadProduct();
    },

    methods: {
        loadProduct() {
            this.isLoading = true;
            this.productRepository
                .get(this.productId, Shopware.Context.api)
                .then((product) => {
                    if (!product.customFields) {
                        product.customFields = {};
                    }
                    this.product = product;
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },

        getCustomField(suffix) {
            if (!this.product) return null;
            this.product.customFields = this.product.customFields || {};
            return this.product.customFields['rc_custom_field_' + suffix];
        },

        setCustomField(suffix, value) {
            if (!this.product) return;
            this.product.customFields = this.product.customFields || {};
            this.product.customFields['rc_custom_field_' + suffix] = value;
        },

        isEnabled() {
            return !!(this.product && this.product.customFields && this.product.customFields.rc_custom_fields_enabled);
        },

        setEnabled(value) {
            if (!this.product) return;
            this.product.customFields = this.product.customFields || {};
            this.product.customFields.rc_custom_fields_enabled = !!value;
        },

        onSave() {
            if (!this.product) return;
            this.isSaving = true;
            this.productRepository
                .save(this.product, Shopware.Context.api)
                .then(() => {
                    this.createNotificationSuccess({
                        title: this.$tc('global.default.success'),
                        message: this.$tc('rcCustomFields.productTab.title'),
                    });
                })
                .catch((error) => {
                    this.createNotificationError({
                        title: this.$tc('global.default.error'),
                        message: error && error.message ? error.message : '',
                    });
                })
                .finally(() => {
                    this.isSaving = false;
                });
        },
    },
});
