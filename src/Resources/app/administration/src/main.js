// <plugin root>/src/Resources/app/administration/src
import './module/rc-custom-fields-product-tab';

import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';

/*
 * Übersetzungen werden über `Locale.extend` angemeldet. Einen Initializer-Dekorator für
 * `locale` kennt Shopware 6.7 nicht; der Aufruf würfe bei jedem Laden der Administration eine
 * Ausnahme. Die Übersetzungen kämen über die serverseitige Auslieferung zwar trotzdem an, doch
 * ein roter Eintrag in der Konsole macht jede echte Meldung schwerer auffindbar.
 */
const { Locale } = Shopware;

Locale.extend('de-DE', deDE);
Locale.extend('en-GB', enGB);
