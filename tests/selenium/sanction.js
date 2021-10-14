'use strict';

const SanctionsPage = require('./pageobjects/sanctions.page');
const Config = require('./config');
const UserLoginPage = require('wdio-mediawiki/LoginPage');

class Sanction {
  createRandom(target) {
    Config.setVerifications(0, 0);
    UserLoginPage.loginAdmin();
    SanctionsPage.open();
    SanctionsPage.submit(target);

    const result = $('.sanction-execute-result a');
    result.waitForDisplayed();
    let uuid = result.getText();
    if (uuid.includes(':')) {
      uuid = uuid.split(':')[1];
    }
    return uuid.toLowerCase();
  }
}

module.exports = new Sanction();
