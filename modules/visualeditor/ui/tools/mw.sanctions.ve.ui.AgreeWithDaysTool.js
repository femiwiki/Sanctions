(function (mw, OO, ve) {
  'use strict';

  /**
   * Tool for user mentions
   *
   * @class
   * @extends ve.ui.InspectorTool
   *
   * @constructor
   * @param {OO.ui.ToolGroup} toolGroup
   * @param {Object} [config] Configuration options
   */

  mw.sanctions.ve.ui.AgreeWithDaysTool =
    function SanctionsVeUiAgreeWithDaysInspectorTool() {
      var self = this;
      $(function () {
        var title = self.$element
          .closest('.flow-topic')
          .find('.flow-topic-title')
          .text();
        if (
          title.match(
            new RegExp(mw.config.get('wgSanctionsInsultingNameTopicTitle'))
          )
        ) {
          self.destroy();
        }
      });
      // Parent constructor
      mw.sanctions.ve.ui.AgreeWithDaysTool.super.apply(this, arguments);
    };

  OO.inheritClass(mw.sanctions.ve.ui.AgreeWithDaysTool, ve.ui.InspectorTool);

  // Static
  mw.sanctions.ve.ui.AgreeWithDaysTool.static.commandName =
    'sanctions-agreewithdays';
  mw.sanctions.ve.ui.AgreeWithDaysTool.static.name = 'sanctions-agreewithdays';
  mw.sanctions.ve.ui.AgreeWithDaysTool.static.icon = 'support';
  mw.sanctions.ve.ui.AgreeWithDaysTool.static.title = OO.ui.deferMsg(
    'sanctions-ve-vote-agree-tool-title'
  );

  mw.sanctions.ve.ui.AgreeWithDaysTool.static.template =
    mw.sanctions.ve.ui.AgreeInspector.static.template;

  /**
   * Checks whether the model represents a user mention
   *
   * @param {ve.dm.Model} model
   * @return {boolean}
   */
  mw.sanctions.ve.ui.AgreeWithDaysTool.static.isCompatibleWith = function (
    model
  ) {
    return (
      model instanceof ve.dm.MWTransclusionNode &&
      model.isSingleTemplate(
        mw.sanctions.ve.ui.AgreeWithDaysTool.static.template
      )
    );
  };

  ve.ui.commandRegistry.register(
    new ve.ui.Command('sanctions-agreewithdays', 'window', 'open', {
      args: ['sanctions-agree'],
      supportedSelections: ['linear'],
    })
  );
  ve.ui.toolFactory.register(mw.sanctions.ve.ui.AgreeWithDaysTool);
})(mediaWiki, OO, ve);
