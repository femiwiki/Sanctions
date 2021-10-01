'use strict';

const assert = require('assert'),
const SanctionsPage = require('../pageobjects/sanctions.page');
// const Util = require('wdio-mediawiki/Util');
// const Api = require('wdio-mediawiki/Api');

describe('Special:Sanctions', function () {
  let bot;

  before(async () => {
    // bot = await Api.bot();
  });

  it('shows not-loggedin warning to an anonymous user @daily', function () {
    SanctionsPage.open();

    assert.strictEqual(
      SanctionsPage.reasonsDisabledParticipation.getText(),
      'Not logged in.'
    );
  });
});
