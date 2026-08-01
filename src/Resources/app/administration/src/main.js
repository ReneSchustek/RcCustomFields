// <plugin root>/src/Resources/app/administration/src
import './module/rc-custom-fields-product-tab';

import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';

const { Application } = Shopware;

Application.addInitializerDecorator('locale', (localeFactory) => {
    localeFactory.extend('de-DE', deDE);
    localeFactory.extend('en-GB', enGB);

    return localeFactory;
});
