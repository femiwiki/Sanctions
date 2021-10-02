'use strict';

const assert = require('assert');
const SanctionsPage = require('../pageobjects/sanctions.page');
// const Util = require('wdio-mediawiki/Util');
const Api = require('wdio-mediawiki/Api');

describe('Special:Sanctions', function () {
  let bot;

  before(async () => {
    bot = await Api.bot();
  });

  afterEach(async () => {
    bot.delete('MediaWiki:sanctions-voting-right-verification-period');
    bot.delete('MediaWiki:sanctions-voting-right-verification-edits');
  });

  it('shows not-loggedin warning to an anonymous user @daily', function () {
    SanctionsPage.open();

    assert.strictEqual(
      SanctionsPage.reasonsDisabledParticipation.getText(),
      '(sanctions-reason-not-logged-in)'
    );
  });

  it('shows disabled reasons to new user @daily', function () {
    UserLoginPage.login(browser.config.mwUser, browser.config.mwPwd);
    SanctionsPage.open();

    assert.match(
      SanctionsPage.reasonsDisabledParticipation.getText(),
      /\(sanctions-reason-unsatisfying-verification-period: 20, .+\)/
    );
  });

  it('shows the edit count to user does not have enough edits @daily', function () {
    browser.call(async () => {
      await bot.edit(
        'MediaWiki:sanctions-voting-right-verification-period',
        '0',
        'create for edit'
      );
    });

    UserLoginPage.login(browser.config.mwUser, browser.config.mwPwd);
    SanctionsPage.open();

    assert.strictEqual(
      SanctionsPage.reasonsDisabledParticipation.getText(),
      '(sanctions-reason-unsatisfying-verification-edits: 0, 0, 3)'
    );
  });

  it('does not show any warning to user matches all condition @daily', function () {
    browser.call(async () => {
      await bot.edit(
        'MediaWiki:sanctions-voting-right-verification-period',
        '0',
        'create for edit'
      );
      await bot.edit(
        'MediaWiki:sanctions-voting-right-verification-edits',
        '0',
        'create for edit'
      );
    });

    UserLoginPage.login(browser.config.mwUser, browser.config.mwPwd);
    SanctionsPage.open();

    assert.ok(!SanctionsPage.reasonsDisabledParticipation.getText());
  });
});
