'use strict';

const assert = require('assert');
const SanctionsPage = require('../pageobjects/sanctions.page');
const UserLoginPage = require('wdio-mediawiki/LoginPage');
const Api = require('wdio-mediawiki/Api');
const Util = require('wdio-mediawiki/Util');
const Config = require('../config');

describe('A user', () => {
  let bot;

  before(async () => {
    bot = await Api.bot();
  });

  it('should be able to create the first sanction', () => {
    Config.setVerifications(0, 0);
    const discussionPage = Util.getTestString('Sanctions-discussion-');
    Config.discussionPage = discussionPage;

    const targetName = Util.getTestString('Sanction-target-');
    browser.call(async () => {
      await Api.createAccount(bot, targetName, Util.getTestString());
    });

    UserLoginPage.login(browser.config.mwUser, browser.config.mwPwd);
    SanctionsPage.open();
    SanctionsPage.submit(targetName);

    assert.ok(!/An error has occurred/.test($('.mw-body-content').getText()));
    assert.ok(
      new RegExp(`${targetName}`).test(SanctionsPage.sanctions.getText())
    );
  });
});
