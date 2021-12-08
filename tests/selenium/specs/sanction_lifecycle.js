'use strict';

const Api = require('wdio-mediawiki/Api');
const assert = require('assert');
const Config = require('../config');
const FlowApi = require('../flow_api');
const FlowTopic = require('../pageobjects/flow_topic.page');
const Page = require('wdio-mediawiki/Page');
const Sanction = require('../sanction');
const SanctionsPage = require('../pageobjects/sanctions.page');
const UserLoginPage = require('wdio-mediawiki/LoginPage');
const Util = require('wdio-mediawiki/Util');

function queryBlocks() {
  const blocks = browser.call(() =>
    Api.bot().then((bot) =>
      bot.request({ action: 'query', list: 'blocks', bkprop: 'user' })
    )
  )['query']['blocks'];
  if (!blocks) {
    return [];
  }
  return blocks.map((e) => e['user']);
}

describe('Sanction', () => {
  let targetName, targetPassword;
  const voters = [];
  let bot;

  before(async () => {
    bot = await Api.bot();
    Config.verificationPeriod = 0;
    Config.verificationEdits = 0;
    Config.votingPeriod = 10 /* seconds */ / (24 * 60 * 60);
    targetName = Util.getTestString('Sanction-target-');
    targetPassword = Util.getTestString();
    await Api.createAccount(bot, targetName, targetPassword);

    // Create voter accounts
    for (let count = 0; count < 3; count++) {
      const username = Util.getTestString(`Sanction-voter${count}-`);
      const password = Util.getTestString();
      await Api.createAccount(bot, username, password);
      voters.push(await Api.bot(username, password));
    }
  });

  afterEach(() => {
    SanctionsPage.open();
    assert.strictEqual(
      '(sanctions-empty-now)',
      SanctionsPage.sanctions.getText()
    );
    Api.unblockUser(bot, targetName);
  });

  it('should be canceled by the author', () => {
    const uuid = Sanction.create(targetName);
    Sanction.open(uuid);

    FlowTopic.reply('{{Oppose}}');

    browser.refresh();
    browser.pause(500);
    browser.refresh();

    assert.strictEqual(
      "Status: Rejected (Canceled by the sanction's author.)",
      FlowTopic.topicSummary.getText()
    );
  });

  it('should be rejected if three users object', () => {
    const uuid = Sanction.create(targetName);

    for (let count = 0; count < 3; count++) {
      FlowApi.reply('{{Oppose}}', uuid, voters[count]);
    }

    Sanction.open(uuid);
    assert.strictEqual(
      'Status: Immediately rejected (Rejected by first three participants.)',
      FlowTopic.topicSummary.getText()
    );
  });

  it('should be passed if three users support before expired', () => {
    // Create a sanction
    const uuid = Sanction.create(targetName);
    const created = new Date().getTime();

    for (let count = 0; count < 3; count++) {
      FlowApi.reply('{{Support}}', uuid, voters[count]);
    }

    browser.refresh();
    // Wait for topic summary is updated by the bot.
    browser.pause(500);

    Sanction.open(uuid);
    assert.ok(
      FlowTopic.topicSummary
        .getText()
        .includes('Status: Passed to block 1 day(s) (prediction)'),
      FlowTopic.topicSummary.getText()
    );

    const spentTime = new Date().getTime() - created;
    browser.pause(10000 - spentTime);
    browser.refresh();

    const blocks = queryBlocks();
    assert.notEqual(-1, blocks.indexOf(targetName), 'Block list: ' + blocks);
  });

  it('should block the target user of the passed sanction when logged in', () => {
    // Create a sanction
    const uuid = Sanction.create(targetName);
    const created = new Date().getTime();

    for (let count = 0; count < 3; count++) {
      FlowApi.reply('{{Support}}', uuid, voters[count]);
    }

    browser.refresh();
    // Wait for topic summary is updated by the bot.
    browser.pause(500);

    Sanction.open(uuid);
    assert.ok(
      FlowTopic.topicSummary
        .getText()
        .includes('Status: Passed to block 1 day(s) (prediction)'),
      FlowTopic.topicSummary.getText()
    );

    const spentTime = new Date().getTime() - created;
    browser.pause(10000 - spentTime);

    UserLoginPage.login(targetName, targetPassword);

    const blocks = queryBlocks();
    assert.notEqual(-1, blocks.indexOf(targetName), 'Block list: ' + blocks);
  });
});
