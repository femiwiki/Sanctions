'use strict';
const Page = require('wdio-mediawiki/Page');

class SanctionsPage extends Page {
  get reasonsDisabledParticipation() {
    return $('.sanctions-reasons-disabled-participation');
  }
  get target() {
    return $('#sanctions-target');
  }
  get forInsultingName() {
    return $('#forInsultingName');
  }
  get submitButton() {
    return $('#sanctions-submit-button');
  }
  get sanctions() {
    return $('.sanctions');
  }
  get sanctionLink() {
    return $('.sanction a.sanction-type');
  }
  get votedSanctions() {
    return $$('.sanction.voted');
  }

  open(subpage) {
    super.openTitle('Special:Sanctions/' + subpage, { uselang: 'qqx' });
  }

  waitUntilUserIsNotNew() {
    let text;
    do {
      text = this.reasonsDisabledParticipation.getText();

      // Wait
      browser.pause(1000);
      browser.refresh();
    } while (/sanctions-reason-unsatisfying-verification-period/.test(text));
  }

  submit(target, isForInsultingName = false) {
    this.target.waitForDisplayed();
    this.target.setValue(target);
    if (isForInsultingName) {
      this.forInsultingName.click();
    }
    this.submitButton.click();
  }
}
module.exports = new SanctionsPage();
