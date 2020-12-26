(function (mw, OO, ve) {
  'use strict';

  /**
   * Tool for disagree
   *
   * @class
   * @extends ve.ui.InspectorTool
   *
   * @constructor
   * @param {OO.ui.ToolGroup} toolGroup
   * @param {Object} [config] Configuration options
   */

  mw.sanctions.ve.ui.DisagreeTool = function SanctionsVeUiDisagreeTool() {
    // Parent constructor
    mw.sanctions.ve.ui.DisagreeTool.super.apply(this, arguments);
  };

  OO.inheritClass(mw.sanctions.ve.ui.DisagreeTool, ve.ui.FragmentWindowTool);

  // Static
  mw.sanctions.ve.ui.DisagreeTool.static.commandName = 'sanctions-disagree';
  mw.sanctions.ve.ui.DisagreeTool.static.name = 'sanctions-disagree';
  mw.sanctions.ve.ui.DisagreeTool.static.icon = 'oppose';
  mw.sanctions.ve.ui.DisagreeTool.static.title = OO.ui.deferMsg(
    'sanctions-ve-vote-disagree-tool-title'
  );

  ve.ui.toolFactory.register(mw.sanctions.ve.ui.DisagreeTool);
})(mediaWiki, OO, ve);
