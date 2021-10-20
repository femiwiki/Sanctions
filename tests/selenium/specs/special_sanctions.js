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

  afterEach(() => {
    SanctionsPage.open();
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
      Config.verificationPeriod = 10;
      Config.verificationEdits = 0;
      UserLoginPage.loginAdmin();
      SanctionsPage.open();

      assert.ok(
        /\(sanctions-reason-unsatisfying-verification-period: 10, .+\)/.test(
          SanctionsPage.reasonsDisabledParticipation.getText()
        )
      );
    });

    it('a user does not have enough edit count the edit count', () => {
      Config.verificationPeriod = 0;
      Config.verificationEdits = 10;

      UserLoginPage.loginAdmin();
      SanctionsPage.open();

      assert.strictEqual(
        SanctionsPage.reasonsDisabledParticipation.getText(),
        '(sanctions-reason-unsatisfying-verification-edits: 0, 0, 10)'
      );
    });
  });

  it('should hide and show the form as the conditions change', () => {
    const username = Util.getTestString('Sanction-user-');
    const password = Util.getTestString();
    browser.call(async () => {
      await Api.createAccount(bot, username, password);
    });
    UserLoginPage.login(username, password);

    Config.verificationPeriod = 3;
    SanctionsPage.open();
    let warnings = SanctionsPage.reasonsDisabledParticipation.getText();
    assert.ok(
      /sanctions-reason-unsatisfying-verification-period/.test(warnings),
      'There should be a warning about the creation time. ' + warnings
    );
    Config.verificationPeriod = 15 /* seconds */ / (24 * 60 * 60);

    Config.verificationEdits = 1;
    SanctionsPage.open();
    warnings = SanctionsPage.reasonsDisabledParticipation.getText();
    assert.ok(
      /sanctions-reason-unsatisfying-verification-edits/.test(warnings),
      'There should be a warning about the edit count. ' + warnings
    );

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
    Config.verificationPeriod = 0;
    Config.verificationEdits = 0;

    UserLoginPage.loginAdmin();
    SanctionsPage.open();

    assert.ok(!SanctionsPage.reasonsDisabledParticipation.getText());
  });

  it('should remove voted tag on a sanction', () => {
    Config.verificationPeriod = 0;
    Config.verificationEdits = 0;
    Config.votingPeriod = 1;

    // Create a Sanction
    const username = Util.getTestString('Sanction-other-');
    const password = Util.getTestString();
    Api.createAccount(bot, username, password);
    const uuid = Sanction.create(null, username, password);

    // Store the number of voted mark
    UserLoginPage.loginAdmin();
    SanctionsPage.open();
    const voted = SanctionsPage.votedSanctions.length;

    // Vote
    FlowApi.reply('{{Oppose}}', uuid, bot);
    SanctionsPage.open();

    const newVoted = SanctionsPage.votedSanctions.length;
    assert.equal(1, newVoted - voted);

    // Cancel the sanction
    for (let count = 0; count < 2; count++) {
      FlowApi.reply('{{Oppose}}', uuid, voters[count]);
    }
  });
});
