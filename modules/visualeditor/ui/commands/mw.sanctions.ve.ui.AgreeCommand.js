(function (mw, OO, ve) {
  'use strict';
  /**
   * Agree command.
   *
   * @class
   * @extends ve.ui.Command
   *
   * @constructor
   * @param {string} name
   * @param {string} method
   */
  mw.sanctions.ve.ui.AgreeCommand = function SanctionsVeUiAgreeCommand() {
    // Parent constructor
    mw.sanctions.ve.ui.AgreeCommand.super.call(
      this,
      'sanctions-agree',
      null,
      null,
      { supportedSelections: ['linear'] }
    );
  };

  /* Inheritance */

  OO.inheritClass(mw.sanctions.ve.ui.AgreeCommand, ve.ui.Command);

  /* Methods */

  /**
   * @inheritdoc
   */
  mw.sanctions.ve.ui.AgreeCommand.prototype.execute = function (surface) {
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
                      href: mw.sanctions.ve.ui.AgreeCommand.static.template,
                      wt: mw.sanctions.ve.ui.AgreeCommand.static.template,
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
  mw.sanctions.ve.ui.AgreeCommand.static.template = mw.config.get(
    'wgSanctionsAgreeTemplate'
  );

  /* Registration */

  ve.ui.commandRegistry.register(new mw.sanctions.ve.ui.AgreeCommand());
})(mediaWiki, OO, ve);
