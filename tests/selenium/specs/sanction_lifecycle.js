'use strict';

const Api = require('wdio-mediawiki/Api');
const assert = require('assert');
const Config = require('../config');
const FlowApi = require('../flow_api');
const FlowTopic = require('../pageobjects/flow_topic.page');
const Sanction = require('../sanction');
const UserLoginPage = require('wdio-mediawiki/LoginPage');
const Util = require('wdio-mediawiki/Util');

async function queryBlocks() {
  const bot = await Api.bot();
  const result = await bot.request({
    action: 'query',
    list: 'blocks',
    bkprop: 'user',
  });
  if (!result?.query?.blocks) {
    return [];
  }
  return result.query.blocks.map((e) => e['user']);
}

describe('Sanction', () => {
  const targetName = Util.getTestString('Sanction-target-');
  const targetPassword = Util.getTestString();
  let voters;
  let bot;

  before(async () => {
    await new Config().setup();
    bot = await Api.bot();
    await Api.createAccount(bot, targetName, targetPassword);
    voters = await Sanction.createVoters(bot);
  });

  async function createPassedSanction(support = 3, logout = false) {
    // Create a sanction
    const uuid = await Sanction.create(targetName);
    const created = new Date().getTime();

    for (let count = 0; count < support; count++) {
      await FlowApi.reply('{{Support}}', uuid, voters[count]);
    }

    // Wait for topic summary is updated by the bot.
    await browser.pause(500);

    await Sanction.open(uuid);

    await FlowTopic.topicSummary.waitForDisplayed();
    const summaryText = await FlowTopic.topicSummary.getText();
    assert.ok(
      summaryText.includes('Status: Passed to block 1 day(s) (prediction)'),
      summaryText
    );

    if (logout) {
      await browser.deleteAllCookies();
    }

    const spentTime = new Date().getTime() - created;
    // Wait until expired
    await browser.pause(Config.VOTING_PERIOD * 1000 - spentTime);
    return uuid;
  }

  it('should be canceled by the author', async () => {
    const uuid = await Sanction.create(targetName);
    await Sanction.open(uuid);
    await FlowApi.reply('{{Oppose}}', uuid, bot);

    await browser.pause(500);
    browser.refresh();
    assert.strictEqual(
      "Status: Rejected (Canceled by the sanction's author.)",
      await FlowTopic.topicSummary.getText()
    );
  });

  it('should be rejected if three users object', async () => {
    const uuid = await Sanction.create(targetName);

    for (let count = 0; count < 3; count++) {
      await FlowApi.reply('{{Oppose}}', uuid, voters[count]);
    }

    await browser.pause(500);
    await Sanction.open(uuid);
    assert.strictEqual(
      'Status: Immediately rejected (Rejected by first three participants.)',
      await FlowTopic.topicSummary.getText()
    );
  });

  it('should be passed if three users support before expired', async () => {
    await createPassedSanction();
    browser.refresh();

    const blocks = await queryBlocks();
    assert.ok(blocks.indexOf(targetName) !== -1, 'Block list: ' + blocks);
    await Api.unblockUser(bot, targetName);
  });

  it('should block the target user of the passed sanction when logged in', async () => {
    await createPassedSanction();
    await UserLoginPage.login(targetName, targetPassword);

    const blocks = await queryBlocks();
    assert.ok(blocks.indexOf(targetName) !== -1, 'Block list: ' + blocks);
    await Api.unblockUser(bot, targetName);
  });

  // This tests https://github.com/femiwiki/Sanctions/issues/223
  it('should not touch the summary of a expired handled sanction', async () => {
    // Create a sanction
    const uuid = await createPassedSanction(1, true);

    // Log in as the target user
    await UserLoginPage.login(targetName, targetPassword);

    await Sanction.open(uuid);
    let summary = await FlowTopic.topicSummary.getText();
    assert.ok(
      summary.includes('Status: Passed to block 1 day(s)'),
      'The summary does not have expected value: ' + summary
    );

    const manualSum = 'Manually touched summary.';
    await FlowApi.editTopicSummary(manualSum, uuid, bot);
    await FlowApi.reply('An additional comment.', uuid, bot);

    browser.refresh();
    summary = await FlowTopic.topicSummary.getText();
    assert.ok(
      summary.includes(manualSum),
      'The summary does not have expected value: ' + summary
    );
    await Api.unblockUser(bot, targetName);
  });
});
