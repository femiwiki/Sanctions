(function (mw, OO, ve) {
  'use strict';
  /**
   * Disagree command.
   *
   * @class
   * @extends ve.ui.Command
   *
   * @constructor
   * @param {string} name
   * @param {string} method
   */
  mw.sanctions.ve.ui.DisagreeCommand = function SanctionsVeUiDisagreeCommand() {
    // Parent constructor
    mw.sanctions.ve.ui.DisagreeCommand.super.call(
      this,
      'sanctions-disagree',
      null,
      null,
      { supportedSelections: ['linear'] }
    );
  };

  /* Inheritance */

  OO.inheritClass(mw.sanctions.ve.ui.DisagreeCommand, ve.ui.Command);

  /* Methods */

  /**
   * @inheritdoc
   */
  mw.sanctions.ve.ui.DisagreeCommand.prototype.execute = function (surface) {
    surface
      .getModel()
      .getFragment()
      .insertContent([
        {
          type: 'mwTransclusionBlock',
          attributes: {
            mw: {
              parts: [
                {
                  template: {
                    target: {
                      href: mw.sanctions.ve.ui.DisagreeCommand.static.template,
                      wt: mw.sanctions.ve.ui.DisagreeCommand.static.template,
                    },
                    params: {},
                  },
                },
              ],
            },
          },
        },
        { type: '/mwTransclusionBlock' },
      ])
      .collapseToEnd()
      .select();

    return true;
  };

  // Static
  mw.sanctions.ve.ui.DisagreeCommand.static.template = mw.config.get(
    'wgSanctionsDisagreeTemplate'
  );

  /* Registration */

  ve.ui.commandRegistry.register(new mw.sanctions.ve.ui.DisagreeCommand());
})(mediaWiki, OO, ve);
