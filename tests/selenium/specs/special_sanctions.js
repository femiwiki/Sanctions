'use strict';

const Api = require('wdio-mediawiki/Api');
const assert = require('assert');
const Config = require('../config');
const FlowApi = require('../flow_api');
const Sanction = require('../sanction');
const SanctionsPage = require('../pageobjects/sanctions.page');
const UserLoginPage = require('wdio-mediawiki/LoginPage');
const Util = require('wdio-mediawiki/Util');

describe('Special:Sanctions', () => {
  const voters = [];
  let bot;

  before(async () => {
    bot = await Api.bot();

    // Create voter accounts
    for (let count = 0; count < 2; count++) {
      const username = Util.getTestString(`Sanction-voter${count}-`);
      const password = Util.getTestString();
      await Api.createAccount(bot, username, password);
      voters.push(await Api.bot(username, password));
    }
  });

  afterEach(async () => {
    await SanctionsPage.open();
    browser.refresh();
    assert.strictEqual(
      '(sanctions-empty-now)',
      await SanctionsPage.sanctions.getText()
    );
  });

  describe('should show', () => {
    it('an anonymous user not-logged-in warning', async () => {
      // logout
      browser.deleteCookies();
      await SanctionsPage.open();

      assert.ok(await SanctionsPage.reasonsDisabledParticipation.isExisting());
      assert.strictEqual(
        '(sanctions-reason-not-logged-in)',
        await SanctionsPage.reasonsDisabledParticipation.getText()
      );
    });

    it('a newly registered user that you are too new', async () => {
      await Config.setVerificationPeriod(10);
      await Config.setVerificationEdits(0);
      await UserLoginPage.loginAdmin();
      await SanctionsPage.open();

      assert.ok(
        /\(sanctions-reason-unsatisfying-verification-period: 10, .+\)/.test(
          await SanctionsPage.reasonsDisabledParticipation.getText()
        )
      );
    });

    it('a user does not have enough edit count the edit count', async () => {
      await Config.setVerificationPeriod(0);
      await Config.setVerificationEdits(10);

      await UserLoginPage.loginAdmin();
      await SanctionsPage.open();

      assert.strictEqual(
        await SanctionsPage.reasonsDisabledParticipation.getText(),
        '(sanctions-reason-unsatisfying-verification-edits: 0, 0, 10)'
      );
    });
  });

  it('should hide and show the form as the conditions change', async () => {
    const username = Util.getTestString('Sanction-user-');
    const password = Util.getTestString();
    await Api.createAccount(bot, username, password);
    await UserLoginPage.login(username, password);

    await Config.setVerificationPeriod(3);
    await SanctionsPage.open();
    let warnings = await SanctionsPage.reasonsDisabledParticipation.getText();
    assert.ok(
      /sanctions-reason-unsatisfying-verification-period/.test(warnings),
      'There should be a warning about the creation time. ' + warnings
    );
    await Config.setVerificationPeriod(15 /* seconds */ / (24 * 60 * 60));

    await Config.setVerificationEdits(1);
    await SanctionsPage.open();
    warnings = await SanctionsPage.reasonsDisabledParticipation.getText();
    assert.ok(
      /sanctions-reason-unsatisfying-verification-edits/.test(warnings),
      'There should be a warning about the edit count. ' + warnings
    );

    await SanctionsPage.waitUntilUserIsNotNew();

    // Do edit
    const user = await Api.bot(username, password);
    await user.edit('Sanctions-dummy-edit', Util.getTestString());

    await SanctionsPage.open();
    assert.strictEqual(
      '',
      await SanctionsPage.reasonsDisabledParticipation.getText(),
      'There should be no warnings'
    );
  });

  it('should not show any warning user matches all conditions', async () => {
    await Config.setVerificationPeriod(0);
    await Config.setVerificationEdits(0);

    await UserLoginPage.loginAdmin();
    await SanctionsPage.open();

    assert.ok(!(await SanctionsPage.reasonsDisabledParticipation.getText()));
  });

  it('should remove voted tag on a sanction', async () => {
    await Config.setVerificationPeriod(0);
    await Config.setVerificationEdits(0);
    await Config.setVotingPeriod(1);

    // Create a Sanction
    const username = Util.getTestString('Sanction-other-');
    const password = Util.getTestString();
    Api.createAccount(bot, username, password);
    const uuid = await Sanction.create(null, username, password);

    // Store the number of voted mark
    await UserLoginPage.loginAdmin();
    await SanctionsPage.open();
    assert.equal(0, await SanctionsPage.votedSanctions.length);

    // Vote
    await FlowApi.reply('{{Oppose}}', uuid, bot);
    browser.pause(500);
    browser.refresh();

    assert.equal(1, await SanctionsPage.votedSanctions.length);

    // Cancel the sanction
    for (let count = 0; count < 2; count++) {
      await FlowApi.reply('{{Oppose}}', uuid, voters[count]);
    }
  });
});
