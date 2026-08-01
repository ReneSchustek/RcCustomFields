import template from './sw-product-detail.html.twig';

Shopware.Component.override('sw-product-detail', {
    template,
});

Shopware.Module.register('rc-custom-fields-product-tab-route', {
    type: 'plugin',
    name: 'rc-custom-fields-product-tab',
    title: 'rcCustomFields.productTab.title',

    routeMiddleware(next, currentRoute) {
        // Fuegt die neue Route nur an `sw.product.detail` an.
        if (currentRoute.name === 'sw.product.detail') {
            currentRoute.children = currentRoute.children || [];
            const exists = currentRoute.children.some((c) => c.name === 'sw.product.detail.rc-custom-fields');
            if (!exists) {
                currentRoute.children.push({
                    name: 'sw.product.detail.rc-custom-fields',
                    path: '/sw/product/detail/:id/rc-custom-fields',
                    component: 'rc-cf-product-tab',
                    meta: {
                        parentPath: 'sw.product.index',
                    },
                });
            }
        }
        next(currentRoute);
    },
});
