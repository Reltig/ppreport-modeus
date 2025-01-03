
/**
 * Allow the user to search for learners within the user report.
 *
 * @module    ppreport/user
 */
import UserSearch from 'core_user/comboboxsearch/user';
import Url from 'core/url';
import {renderForPromise, replaceNodeContents} from 'core/templates';
import * as Repository from 'core_grades/searchwidget/repository';

export default class User extends UserSearch {

    constructor() {
        super();
    }

    static init() {
        return new User();
    }

    /**
     * Build the content then replace the node.
     */
    async renderDropdown() {
        const {html, js} = await renderForPromise('core_user/comboboxsearch/resultset', {
            users: this.getMatchedResults().slice(0, 5),
            hasresults: this.getMatchedResults().length > 0,
            instance: this.instance,
            matches: this.getDatasetSize(),
            searchterm: this.getSearchTerm(),
            selectall: this.selectAllResultsLink(),
        });
        replaceNodeContents(this.getHTMLElements().searchDropdown, html, js);
        // Remove aria-activedescendant when the available options change.
        this.searchInput.removeAttribute('aria-activedescendant');
    }

    /**
     * Build up the view all link.
     *
     * @returns {string|*}
     */
    selectAllResultsLink() {
        return Url.relativeUrl('/grade/report/ppreport/index.php', {
            id: this.courseID,
            userid: 0
        }, false);
    }

    /**
     * Build up the link that is dedicated to a particular result.
     *
     * @param {Number} userID The ID of the user selected.
     * @returns {string|*}
     */
    selectOneLink(userID) {
        return Url.relativeUrl('/grade/report/ppreport/index.php', {
            id: this.courseID,
            userid: userID,
        }, false);
    }

    /**
     * Get the data we will be searching against in this component.
     *
     * @returns {Promise<*>}
     */
    fetchDataset() {
        // Small typing checks as sometimes groups don't exist therefore the element returns a empty string.
        const gts = typeof (this.groupID) === "string" && this.groupID === '' ? 0 : this.groupID;
        return Repository.userFetch(this.courseID, gts).then((r) => r.users);
    }
}
