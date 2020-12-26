(function (mw, OO, ve) {
  'use strict';

  /**
   * Tool for agree
   *
   * @class
   * @extends ve.ui.InspectorTool
   *
   * @constructor
   * @param {OO.ui.ToolGroup} toolGroup
   * @param {Object} [config] Configuration options
   */

  mw.sanctions.ve.ui.AgreeTool = function SanctionsVeUiAgreeTool() {
    var self = this;
    $(function () {
      var title = self.$element
        .closest('.flow-topic')
        .find('.flow-topic-title')
        .text();
      if (
        !title.match(
          new RegExp(mw.config.get('wgSanctionsInsultingNameTopicTitle'))
        )
      ) {
        self.destroy();
      }
    });
    // Parent constructor
    mw.sanctions.ve.ui.AgreeTool.super.apply(this, arguments);
  };

  OO.inheritClass(mw.sanctions.ve.ui.AgreeTool, ve.ui.Tool);

  // Static
  mw.sanctions.ve.ui.AgreeTool.static.commandName = 'sanctions-agree';
  mw.sanctions.ve.ui.AgreeTool.static.name = 'sanctions-agree';
  mw.sanctions.ve.ui.AgreeTool.static.icon = 'support';
  mw.sanctions.ve.ui.AgreeTool.static.group = 'textStyle';
  mw.sanctions.ve.ui.AgreeTool.static.title = OO.ui.deferMsg(
    'sanctions-ve-vote-agree-tool-title'
  );

  ve.ui.toolFactory.register(mw.sanctions.ve.ui.AgreeTool);
})(mediaWiki, OO, ve);
