// <plugin root>/src/Resources/app/administration/src
import './module/rc-custom-fields-product-tab';

import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';

/*
 * Übersetzungen werden über `Locale.extend` angemeldet. Den früheren Umweg über einen
 * Initializer-Dekorator für `locale` gibt es in Shopware 6.7 nicht mehr — der Aufruf warf bei
 * jedem Laden der Administration eine Ausnahme. Folgenlos war er trotzdem, weil die
 * Übersetzungen über die serverseitige Auslieferung ankommen; ein roter Eintrag in der Konsole
 * macht aber jede echte Meldung schwerer auffindbar.
 */
const { Locale } = Shopware;

Locale.extend('de-DE', deDE);
Locale.extend('en-GB', enGB);
