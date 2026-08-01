import RcCustomFieldsPlugin from './rc-custom-fields/rc-custom-fields.plugin';

const PluginManager = window.PluginManager;

PluginManager.register('RcCustomFields', RcCustomFieldsPlugin, '[data-rc-custom-fields]');
