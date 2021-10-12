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

  it('should hide or the form as the conditions change', () => {
    Config.setVerifications(5 /* seconds */ / (24 * 60 * 60), 1);
    const username = Util.getTestString('User-');
    const password = Util.getTestString();
    let creationTime;
    browser.call(async () => {
      await Api.createAccount(bot, username, password);
      creationTime = new Date().getTime();
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

  it('should not show any warning to user matches all conditions', () => {
    Config.setVerifications(0, 0);

    UserLoginPage.login(browser.config.mwUser, browser.config.mwPwd);
    SanctionsPage.open();

    assert.ok(!SanctionsPage.reasonsDisabledParticipation.getText());
  });
});
