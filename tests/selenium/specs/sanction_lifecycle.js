'use strict';

const Api = require('wdio-mediawiki/Api');
const assert = require('assert');
const Config = require('../config');
const FlowApi = require('../flow_api');
const FlowTopic = require('../pageobjects/flow_topic.page');
const Sanction = require('../sanction');
const SanctionsPage = require('../pageobjects/sanctions.page');
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
  let targetName, targetPassword;
  const voters = [];
  let bot;

  async function createPassedSanction(support = 3, logout = false) {
    // Create a sanction
    const uuid = await Sanction.create(targetName);
    const created = new Date().getTime();

    for (let count = 0; count < support; count++) {
      await FlowApi.reply('{{Support}}', uuid, voters[count]);
    }

    browser.refresh();
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
      browser.deleteCookies();
    }

    const spentTime = new Date().getTime() - created;
    await browser.pause(10000 - spentTime);
    return uuid;
  }

  before(async () => {
    bot = await Api.bot();
    await Config.setVerificationPeriod(0);
    await Config.setVerificationEdits(0);
    await Config.setVotingPeriod(10 /* seconds */ / (24 * 60 * 60));
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

  afterEach(async () => {
    await SanctionsPage.open();
    assert.strictEqual(
      '(sanctions-empty-now)',
      await SanctionsPage.sanctions.getText()
    );
  });

  it('should be canceled by the author', async () => {
    const uuid = await Sanction.create(targetName);
    await Sanction.open(uuid);

    await FlowApi.reply('{{Oppose}}', uuid, bot);

    browser.pause(500);
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

    browser.pause(500);
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
    assert.notEqual(-1, blocks.indexOf(targetName), 'Block list: ' + blocks);
    await Api.unblockUser(bot, targetName);
  });

  it('should block the target user of the passed sanction when logged in', async () => {
    await createPassedSanction();
    UserLoginPage.login(targetName, targetPassword);

    const blocks = await queryBlocks();
    assert.notEqual(-1, blocks.indexOf(targetName), 'Block list: ' + blocks);
    await Api.unblockUser(bot, targetName);
  });

  // This tests https://github.com/femiwiki/Sanctions/issues/223
  it('should not touch the summary of a expired handled sanction', async () => {
    // Create a sanction
    const uuid = await createPassedSanction(1, true);

    // Log in as the target user
    UserLoginPage.login(targetName, targetPassword);

    await Sanction.open(uuid);
    assert.ok(
      (await FlowTopic.topicSummary.getText()).includes(
        'Status: Passed to block 1 day(s)'
      ),
      'The summary does not have expected value: ' +
        (await FlowTopic.topicSummary.getText())
    );

    await FlowApi.editTopicSummary('Manually touched summary.', uuid, bot);
    await FlowApi.reply('An additional comment.', uuid, bot);

    browser.refresh();
    assert.ok(
      await FlowTopic.topicSummary
        .getText()
        .includes('Manually touched summary'),
      'The summary does not have expected value: ' +
        (await FlowTopic.topicSummary.getText())
    );
    await Api.unblockUser(bot, targetName);
  });
});
