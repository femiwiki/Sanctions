'use strict';

const assert = require('assert');
const SanctionsPage = require('../pageobjects/sanctions.page');
const FlowTopic = require('../pageobjects/flow-topic.page');
const UserLoginPage = require('wdio-mediawiki/LoginPage');
const Api = require('wdio-mediawiki/Api');
const Util = require('wdio-mediawiki/Util');
const Config = require('../config');

describe('Lifecycle of sanctions', () => {
  let target;
  before(async () => {
    Config.setVerifications(0, 0);
    const bot = await Api.bot();
    target = Util.getTestString('Sanction-target-');
    await Api.createAccount(bot, target, Util.getTestString());
  });

  it('should be possible to self-reject', () => {
    UserLoginPage.login(browser.config.mwUser, browser.config.mwPwd);
    SanctionsPage.open();
    SanctionsPage.submit(target);
    SanctionsPage.getSanctionLink({ mySanction: true }).click();

    FlowTopic.reply('{{Oppose}}');

    browser.refresh();

    // TODO enable this assertion. There is a bug on topic summary.
    // assert.strictEqual(
    //   "Status: Rejected (Canceled by the sanction's author.)",
    //   FlowTopic.topicSummary.getText()
    // );

    SanctionsPage.open();
    assert.ok(SanctionsPage.executeButton.isExisting());
    SanctionsPage.executeButton.click();
    assert.ok(!SanctionsPage.executeButton.isExisting());
  });
});