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
  get executeButton() {
    return $('.sanction-execute-button');
  }
  getSanctionSelector(voted = null, mySanction = null) {
    let classSelector = '.sanction';
    let notClassSelector = '';

    if (mySanction === true) {
      classSelector += '.my-sanction';
    }

    if (voted === null) {
      // nothing to do
    } else if (voted) {
      classSelector += '.voted';
    } else {
      notClassSelector += '.voted';
    }

    let selector = classSelector;
    if (notClassSelector) {
      selector += `:not(${notClassSelector})`;
    }
    return selector;
  }
  getSanctionLink(voted = null, mySanction = null) {
    const selector = this.getSanctionSelector(voted, mySanction);
    return $(`${selector} a.sanction-type`);
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
