'use strict';

const SanctionsPage = require('./pageobjects/sanctions.page');
const Config = require('./config');
const UserLoginPage = require('wdio-mediawiki/LoginPage');

class Sanction {
  createRandom(target) {
    Config.setVerifications(0, 0);
    UserLoginPage.login(browser.config.mwUser, browser.config.mwPwd);
    SanctionsPage.open();
    SanctionsPage.submit(target);
  }
}

module.exports = new Sanction();
