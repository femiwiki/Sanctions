'use strict';

const assert = require('assert');
const SanctionsPage = require('../pageobjects/sanctions.page');
const UserLoginPage = require('wdio-mediawiki/LoginPage');
const Api = require('wdio-mediawiki/Api');
const Util = require('wdio-mediawiki/Util');

describe('Special:Sanctions', function () {
  let bot;

  before(async () => {
    bot = await Api.bot();
  });

  afterEach(async () => {
    bot.delete('MediaWiki:sanctions-voting-right-verification-period');
    bot.delete('MediaWiki:sanctions-voting-right-verification-edits');
  });

  it('shows an anonymous user not-loggedin warning @daily', function () {
    SanctionsPage.open();

    assert.strictEqual(
      SanctionsPage.reasonsDisabledParticipation.getText(),
      '(sanctions-reason-not-logged-in)'
    );
  });

  it('shows a newly registered user that you are too new @daily', function () {
    UserLoginPage.login(browser.config.mwUser, browser.config.mwPwd);
    SanctionsPage.open();

    assert.ok(
      /\(sanctions-reason-unsatisfying-verification-period: 20, .+\)/.test(
        SanctionsPage.reasonsDisabledParticipation.getText()
      )
    );
  });

  it('shows a user does not have enough edit count the edit count @daily', function () {
    browser.call(async () => {
      await bot.edit(
        'MediaWiki:sanctions-voting-right-verification-period',
        '0'
      );
    });

    UserLoginPage.login(browser.config.mwUser, browser.config.mwPwd);
    SanctionsPage.open();

    assert.strictEqual(
      SanctionsPage.reasonsDisabledParticipation.getText(),
      '(sanctions-reason-unsatisfying-verification-edits: 0, 0, 3)'
    );
  });

  it('hide or show the form as the conditions change @daily', function () {
    const username = Util.getTestString('User-');
    const password = Util.getTestString();
    let creationTime;
    browser.call(async () => {
      await Api.createAccount(bot, username, password);
      creationTime = new Date().getTime();
      await bot.edit(
        'MediaWiki:sanctions-voting-right-verification-period',
        '' + 5 /* seconds */ / (24 * 60 * 60)
      );
      await bot.edit(
        'MediaWiki:sanctions-voting-right-verification-edits',
        '1'
      );
    });

    UserLoginPage.login(username, password);
    SanctionsPage.open();
    assert.ok(
      /\(sanctions-reason-unsatisfying-verification-edits: .+, 0, 1\)/.test(
        SanctionsPage.reasonsDisabledParticipation.getText()
      )
    );

    browser.call(async () => {
      const user = await Api.bot(username, password);
      await user.edit(
        Util.getTestString('Sanctions-edit-'),
        Util.getTestString()
      );
    });
    const spentSeconds = new Date().getTime() - creationTime;
    if (spentSeconds < 5000) {
      SanctionsPage.open();
      const text = SanctionsPage.reasonsDisabledParticipation.getText();
      assert.ok(
        /sanctions-reason-unsatisfying-verification-period/.test(text),
        'reject for creation time'
      );

      // Wait
      browser.pause(5000 - spentSeconds);
    }
    SanctionsPage.open();
    const text = SanctionsPage.reasonsDisabledParticipation.getText();
    assert.ok(
      !/sanctions-reason-unsatisfying-verification-period/.test(text),
      'does not prevent for creation time'
    );
    assert.ok(
      !/sanctions-reason-unsatisfying-verification-edits/.test(text),
      'does not prevent for edit count'
    );
  });

  it('does not show any warning to user matches all conditions @daily', function () {
    browser.call(async () => {
      await bot.edit(
        'MediaWiki:sanctions-voting-right-verification-period',
        '0'
      );
      await bot.edit(
        'MediaWiki:sanctions-voting-right-verification-edits',
        '0'
      );
    });

    UserLoginPage.login(browser.config.mwUser, browser.config.mwPwd);
    SanctionsPage.open();

    assert.ok(!SanctionsPage.reasonsDisabledParticipation.getText());
  });
});
