'use strict';

const assert = require('assert');
const SanctionsPage = require('../pageobjects/sanctions.page');
const Sanction = require('../sanction');
const FlowTopic = require('../pageobjects/flow_topic.page');
const UserLoginPage = require('wdio-mediawiki/LoginPage');
const Api = require('wdio-mediawiki/Api');
const Util = require('wdio-mediawiki/Util');
const Config = require('../config');

describe('Sanction', () => {
  let target;
  let bot;

  before(async () => {
    Config.setVerifications(0, 0);
    bot = await Api.bot();
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
    SanctionsPage.executeButton.waitForDisplayed();
    SanctionsPage.executeButton.click();
    assert.ok(!SanctionsPage.executeButton.isExisting());
  });

  it('should be rejected if three users object', () => {
    Sanction.createRandom(target);
    for (let count = 0; count < 3; count++) {
      const username = Util.getTestString('Sanction-voter-');
      const password = Util.getTestString();

      browser.call(async () => {
        await Api.createAccount(bot, username, password);
      });
      UserLoginPage.login(username, password);
      SanctionsPage.open();
      SanctionsPage.getSanctionLink(false).click();
      FlowTopic.reply('{{Oppose}}');
    }

    browser.refresh();
    // Wait for topic summary is updated by the bot.
    browser.pause(1000);
    browser.refresh();
    // TODO enable this assertion. There is a bug on topic summary.
    // assert.strictEqual(
    //   'Status: Immediately rejected (Rejected by first three participants.)',
    //   FlowTopic.topicSummary.getText()
    // );
    SanctionsPage.open();
    SanctionsPage.executeButton.waitForDisplayed();
    SanctionsPage.executeButton.click();
  });
});
