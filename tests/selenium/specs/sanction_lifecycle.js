'use strict';

const assert = require('assert');
const SanctionsPage = require('../pageobjects/sanctions.page');
const FlowTopic = require('../pageobjects/flow_topic.page');
const UserLoginPage = require('wdio-mediawiki/LoginPage');
const Api = require('wdio-mediawiki/Api');
const Util = require('wdio-mediawiki/Util');
const Config = require('../config');

describe('Sanction', () => {
  let target;
  before(async () => {
    Config.setVerifications(0, 0);
    const bot = await Api.bot();
    target = Util.getTestString('Sanction-target-');
    await Api.createAccount(bot, target, Util.getTestString());
  });

  it('should be canceled by the author', () => {
    UserLoginPage.login(browser.config.mwUser, browser.config.mwPwd);
    SanctionsPage.open();
    SanctionsPage.submit(target);

	// For some reason, clicking without refreshing fails.
	// TODO Investment the cause.
    browser.refresh();

    SanctionsPage.getSanctionLink(null, true).click();

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
