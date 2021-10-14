'use strict';

const assert = require('assert');
const SanctionsPage = require('../pageobjects/sanctions.page');
const UserLoginPage = require('wdio-mediawiki/LoginPage');
const Api = require('wdio-mediawiki/Api');
const Util = require('wdio-mediawiki/Util');
const Config = require('../config');

describe('Special:Sanctions', () => {
  let bot;

  before(async () => {
    bot = await Api.bot();
  });

  after(async () => {
    await SanctionsPage.open();
    assert.strictEqual(
      '(sanctions-empty-now)',
      SanctionsPage.sanctions.getText()
    );
  });

  describe('should show', () => {
    it('an anonymous user not-logged-in warning', () => {
      // logout
      browser.deleteCookies();
      SanctionsPage.open();

      assert.ok(SanctionsPage.reasonsDisabledParticipation.isExisting());
      assert.strictEqual(
        '(sanctions-reason-not-logged-in)',
        SanctionsPage.reasonsDisabledParticipation.getText()
      );
    });

    it('a newly registered user that you are too new', () => {
      Config.setVerifications(10, 0);
      UserLoginPage.login(browser.config.mwUser, browser.config.mwPwd);
      SanctionsPage.open();

      assert.ok(
        /\(sanctions-reason-unsatisfying-verification-period: 10, .+\)/.test(
          SanctionsPage.reasonsDisabledParticipation.getText()
        )
      );
    });

    it('a user does not have enough edit count the edit count', () => {
      Config.setVerifications(0, 10);

      UserLoginPage.login(browser.config.mwUser, browser.config.mwPwd);
      SanctionsPage.open();

      assert.strictEqual(
        SanctionsPage.reasonsDisabledParticipation.getText(),
        '(sanctions-reason-unsatisfying-verification-edits: 0, 0, 10)'
      );
    });
  });

  it('should hide and show the form as the conditions change', () => {
    Config.setVerifications(10 /* seconds */ / (24 * 60 * 60), 1);
    const username = Util.getTestString('Sanction-user-');
    const password = Util.getTestString();
    browser.call(async () => {
      await Api.createAccount(bot, username, password);
    });

    UserLoginPage.login(username, password);
    SanctionsPage.open();
    let warnings = SanctionsPage.reasonsDisabledParticipation.getText();
    assert.ok(
      /sanctions-reason-unsatisfying-verification-period/.test(warnings),
      'There should be a warning about the creation time. ' + warnings
    );
    assert.ok(
      /sanctions-reason-unsatisfying-verification-edits/.test(warnings),
      'There should be a warning about the edit count. ' + warnings
    );
    SanctionsPage.open();
    SanctionsPage.waitUntilUserIsNotNew();

    // Do edit
    browser.call(async () => {
      const user = await Api.bot(username, password);
      await user.edit('Sanctions-dummy-edit', Util.getTestString());
    });

    SanctionsPage.open();
    assert.strictEqual(
      '',
      SanctionsPage.reasonsDisabledParticipation.getText(),
      'There should be no warnings'
    );
  });

  it('should not show any warning user matches all conditions', () => {
    Config.setVerifications(0, 0);

    UserLoginPage.login(browser.config.mwUser, browser.config.mwPwd);
    SanctionsPage.open();

    assert.ok(!SanctionsPage.reasonsDisabledParticipation.getText());
  });
});
