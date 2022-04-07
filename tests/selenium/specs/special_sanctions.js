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
  let voters;
  let bot;

  before(async () => {
    await new Config().setup();
    bot = await Api.bot();
    voters = await Sanction.createVoters(bot);
  });

  describe('should show', () => {
    it('an anonymous user not-logged-in warning', async () => {
      // Logout
      await browser.deleteAllCookies();
      await SanctionsPage.open();

      assert.ok(await SanctionsPage.reasonsDisabledParticipation.isExisting());
      assert.strictEqual(
        '(sanctions-reason-not-logged-in)',
        await SanctionsPage.reasonsDisabledParticipation.getText()
      );
    });

    it('a newly registered user that you are too new', async () => {
      const username = Util.getTestString(`Sanction-newcomer-`);
      const password = Util.getTestString();
      await Api.createAccount(bot, username, password);

      await UserLoginPage.login(username, password);
      await SanctionsPage.open();

      assert.ok(
        /\(sanctions-reason-unsatisfying-verification-period: \d+\.?\d*, .+\)/.test(
          await SanctionsPage.reasonsDisabledParticipation.getText()
        )
      );
    });
  });

  it('should hide and show the form as the conditions change', async () => {
    const username = Util.getTestString('Sanction-user-');
    const password = Util.getTestString();
    await Api.createAccount(bot, username, password);
    await UserLoginPage.login(username, password);

    await SanctionsPage.open();
    let warnings = await SanctionsPage.reasonsDisabledParticipation.getText();
    assert.ok(
      /sanctions-reason-unsatisfying-verification-period/.test(warnings),
      'There should be warning about the creation time. ' + warnings
    );

    await SanctionsPage.waitUntilUserIsNotNew();

    browser.refresh();
    const warning = await SanctionsPage.reasonsDisabledParticipation.getText();
    assert.ok(warning === '', 'There should be no warnings, but: ' + warning);
  });

  it('should add voted tag on a sanction', async () => {
    // Creates a sanction
    const username = Util.getTestString('Sanction-another-');
    const password = Util.getTestString();
    Api.createAccount(bot, username, password);
    const uuid = await Sanction.create(username, username, password);

    await UserLoginPage.loginAdmin();
    await SanctionsPage.open();
    assert.ok(await $(`#sanction-${uuid}`).isExisting());

    // Votes
    await FlowApi.reply('{{Oppose}}', uuid, bot);
    browser.pause(500);
    browser.refresh();

    assert.ok(await $(`#sanction-${uuid}.voted`).isExisting());

    // Closes the sanction
    for (let count = 0; count < 2; count++) {
      await FlowApi.reply('{{Oppose}}', uuid, voters[count]);
    }
  });
});
