// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Filtering, paging and completion marking for the deadline checker block.
 *
 * This module never calculates a duration and never composes a sentence. PHP has already
 * worked out both the open and the complete wording for every task; marking something
 * complete is a choice between two strings that already exist. That is what keeps the pill,
 * the accessible name and the summary from ever disagreeing with each other.
 *
 * @module     block_deadline_checker/deadlines
 * @copyright  2026 Accipio
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Templates from 'core/templates';
import {getString} from 'core/str';
import Notification from 'core/notification';

const COMPONENT = 'block_deadline_checker';

/**
 * localStorage keys. Deliberately not scoped to the block instance: the design asks for the
 * learner's choice to follow them, so two instances on one page share a view and a filter.
 *
 * Completion lives here only because this build runs on sample data. Real completion is server
 * state and must never be kept in the browser.
 */
const STORE = {
    view: 'dlblock_view',
    course: 'dlblock_course',
    done: 'dlblock_done',
};

/** Below this width the block goes full width and shows fewer rows. */
const COMPACT = '(max-width: 991.98px)';

/**
 * Read a value from localStorage, tolerating browsers that refuse.
 *
 * @param {string} key
 * @return {string|null}
 */
const read = (key) => {
    try {
        return window.localStorage.getItem(key);
    } catch (e) {
        return null;
    }
};

/**
 * Write a value to localStorage, tolerating browsers that refuse.
 *
 * @param {string} key
 * @param {string} value
 */
const write = (key, value) => {
    try {
        window.localStorage.setItem(key, value);
    } catch (e) {
        // Private browsing or a full quota. The block still works, the choice just does not stick.
    }
};

class Deadlines {

    /**
     * @param {HTMLElement} root The block's card element.
     * @param {object} data Payload built by \block_deadline_checker\output\deadlines.
     */
    constructor(root, data) {
        this.root = root;
        this.data = data;

        this.listRegion = this.root.querySelector('[data-region="list"]');
        this.summaryRegion = this.root.querySelector('[data-region="summary"]');
        this.announceRegion = this.root.querySelector('[data-region="announce"]');
        this.courseSelect = this.root.querySelector('[data-region="course"]');

        this.view = ['todo', 'all', 'done'].includes(read(STORE.view)) ? read(STORE.view) : 'todo';
        this.course = read(STORE.course) || 'all';
        this.page = 0;
        this.expanded = null;
        this.overrides = this.restoreOverrides();

        // A stored course that no longer exists would silently hide everything.
        if (this.course !== 'all' && !this.data.courses.some((c) => c.id === this.course)) {
            this.course = 'all';
        }

        this.compact = window.matchMedia(COMPACT);
        this.compact.addEventListener('change', () => this.render());

        this.bind();
        this.render();
    }

    /**
     * Sample completion overrides, stored as a comma separated list of task ids.
     *
     * @return {Set<string>}
     */
    restoreOverrides() {
        const raw = read(STORE.done);
        const known = new Set(this.data.tasks.map((t) => t.id));

        return new Set((raw ? raw.split(',') : []).filter((id) => known.has(id)));
    }

    /**
     * One delegated listener for the whole card, so re-rendering the list never leaves a
     * handler behind.
     */
    bind() {
        this.root.addEventListener('click', (e) => {
            const control = e.target.closest('[data-action]');

            if (!control || !this.root.contains(control)) {
                return;
            }

            const row = control.closest('[data-task]');
            const id = row ? row.dataset.task : null;

            switch (control.dataset.action) {
                case 'view':
                    this.setView(control.dataset.view);
                    break;
                case 'toggle':
                    this.expanded = this.expanded === id ? null : id;
                    this.render(`[data-task="${id}"] [data-action="toggle"]`);
                    break;
                case 'confirm':
                    this.toggleComplete(id);
                    break;
                case 'prev':
                    this.setPage(this.page - 1, '[data-action="prev"]');
                    break;
                case 'next':
                    this.setPage(this.page + 1, '[data-action="next"]');
                    break;
            }
        });

        if (this.courseSelect) {
            this.courseSelect.addEventListener('change', () => {
                this.course = this.courseSelect.value;
                write(STORE.course, this.course);
                this.page = 0;
                this.expanded = null;
                this.render();
            });
        }
    }

    /**
     * Switch status view. Resets to page one and closes any open action strip.
     *
     * @param {string} view todo, all or done
     */
    setView(view) {
        this.view = view;
        write(STORE.view, view);
        this.page = 0;
        this.expanded = null;
        this.render();
    }

    /**
     * Change page and announce the move.
     *
     * @param {number} page Zero-based page number.
     * @param {string} focus Selector to restore focus to.
     */
    setPage(page, focus) {
        this.page = page;
        this.expanded = null;
        this.render(focus, 'page');
    }

    /**
     * Flip a task's completion state.
     *
     * On real data this is where the completion web service call belongs, with the button held
     * in a pending state and rolled back on failure.
     *
     * @param {string} id Task id.
     */
    toggleComplete(id) {
        const task = this.data.tasks.find((t) => t.id === id);

        if (!task) {
            return;
        }

        const wasComplete = this.isComplete(task);

        // The set holds the ids whose state differs from the seed data, so toggling is simply
        // flipping membership. That way a seeded-complete task reopens as readily as a
        // seeded-open one completes.
        if (this.overrides.has(id)) {
            this.overrides.delete(id);
        } else {
            this.overrides.add(id);
        }

        write(STORE.done, Array.from(this.overrides).join(','));

        this.expanded = null;

        // Each state carries the announcement for the action offered *from* that state, so the
        // message comes from where the task was, not where it has just landed.
        this.announce = wasComplete ? task.done.announce : task.open.announce;

        this.render(`[data-task="${id}"] [data-action="toggle"]`);
    }

    /**
     * Whether a task currently counts as complete, seed state plus any learner override.
     *
     * @param {object} task
     * @return {boolean}
     */
    isComplete(task) {
        return this.overrides.has(task.id) ? !task.complete : task.complete;
    }

    /**
     * Rows per page, capped on narrow viewports.
     *
     * @return {number}
     */
    pageSize() {
        return this.compact.matches
            ? Math.min(this.data.compactpagesize, this.data.pagesize)
            : this.data.pagesize;
    }

    /**
     * Tasks in the selected course, whatever their status.
     *
     * @return {object[]}
     */
    scoped() {
        return this.data.tasks.filter((t) => this.course === 'all' || t.courseid === this.course);
    }

    /**
     * Tasks in the selected course and status view, in display order.
     *
     * @return {object[]}
     */
    filtered() {
        const list = this.scoped().filter((t) => {
            if (this.view === 'all') {
                return true;
            }
            return this.view === 'done' ? this.isComplete(t) : !this.isComplete(t);
        });

        const newestFirst = this.view === 'done';

        return list.slice().sort((a, b) => {
            const ac = this.isComplete(a);
            const bc = this.isComplete(b);

            if (ac !== bc) {
                return ac ? 1 : -1;
            }
            return newestFirst ? b.due - a.due : a.due - b.due;
        });
    }

    /**
     * Rebuild the whole card from current state.
     *
     * @param {string} [focus] Selector inside the list to focus once rendered.
     * @param {string} [announceType] Set to "page" to announce the new page number.
     * @return {Promise}
     */
    render(focus, announceType) {
        const list = this.filtered();
        const size = this.pageSize();
        const pageCount = Math.max(1, Math.ceil(list.length / size));

        this.page = Math.min(Math.max(0, this.page), pageCount - 1);

        const start = this.page * size;
        const rows = list.slice(start, start + size).map((t) => this.row(t));

        this.syncViews();
        this.syncCourses();

        return Promise.all([
            this.pageInfo(start, list.length, size),
            this.pageLabel('prev', pageCount),
            this.pageLabel('next', pageCount),
            this.summary(),
            announceType === 'page'
                ? getString('pageannounce', COMPONENT, {page: this.page + 1, total: pageCount})
                : Promise.resolve(this.announce || ''),
        ]).then(([pageinfo, prevlabel, nextlabel, summary, announcement]) => {
            this.announce = null;

            return Templates.renderForPromise(`${COMPONENT}/tasks`, {
                blockid: this.data.blockid,
                pageslabel: this.data.strings.pageslabel,
                rows: rows,
                hasrows: rows.length > 0,
                emptytext: this.emptyText(),
                haspages: pageCount > 1,
                pageinfo: pageinfo,
                prevdisabled: this.page === 0,
                nextdisabled: this.page >= pageCount - 1,
                prevlabel: prevlabel,
                nextlabel: nextlabel,
            }).then(({html, js}) => {
                Templates.replaceNodeContents(this.listRegion, html, js);

                if (this.summaryRegion) {
                    this.summaryRegion.textContent = summary;
                }
                if (this.announceRegion && announcement) {
                    this.announceRegion.textContent = announcement;
                }

                this.restoreFocus(focus);
                return null;
            });
        }).catch(Notification.exception);
    }

    /**
     * Put focus back where the learner left it, since the list they were in has been replaced.
     *
     * @param {string} [selector]
     */
    restoreFocus(selector) {
        if (!selector) {
            return;
        }

        const target = this.listRegion.querySelector(selector);

        if (target && !target.disabled) {
            target.focus();
            return;
        }

        // The control has gone or gone dead: a completed task can leave the page it was on,
        // and a pagination button disables itself at the ends. Land somewhere sensible rather
        // than dropping focus to the top of the document.
        const pager = selector.indexOf('"prev"') !== -1 || selector.indexOf('"next"') !== -1;
        let fallback;

        if (pager) {
            const other = selector.indexOf('"prev"') !== -1 ? 'next' : 'prev';
            fallback = this.listRegion.querySelector(`[data-action="${other}"]:not([disabled])`);
        }

        (fallback || this.listRegion.querySelector('[data-action="toggle"]'))?.focus();
    }

    /**
     * Build a row context, choosing between the wording PHP produced for each state.
     *
     * @param {object} task
     * @return {object}
     */
    row(task) {
        const complete = this.isComplete(task);
        const words = complete ? task.done : task.open;
        const expanded = this.expanded === task.id;

        return {
            id: task.id,
            name: task.name,
            url: task.url,
            haslink: task.haslink,
            meta: words.meta,
            aria: words.aria,
            stamptext: words.stamptext,
            stampvariant: words.stampvariant,
            tinted: words.tinted,
            complete: complete,
            expanded: expanded,
            chevronlabel: expanded ? words.chevronhide : words.chevronshow,
            actioncta: words.actioncta,
            actionaria: words.actionaria,
        };
    }

    /**
     * Reflect the current view on the segmented control.
     */
    syncViews() {
        this.root.querySelectorAll('[data-action="view"]').forEach((button) => {
            const on = button.dataset.view === this.view;
            button.setAttribute('aria-pressed', on ? 'true' : 'false');
            button.classList.toggle('block_deadline_checker__view--on', on);
        });
    }

    /**
     * Offer only courses that have something in the current view, keeping the selected course
     * listed even when it empties so the filter never resets itself under the learner.
     */
    syncCourses() {
        if (!this.courseSelect) {
            return;
        }

        const inView = new Set(
            this.data.tasks
                .filter((t) => {
                    if (this.view === 'all') {
                        return true;
                    }
                    return this.view === 'done' ? this.isComplete(t) : !this.isComplete(t);
                })
                .map((t) => t.courseid)
        );

        const wanted = [{id: 'all', name: this.data.strings.allcourses}].concat(
            this.data.courses.filter((c) => inView.has(c.id) || c.id === this.course)
        );

        const current = Array.from(this.courseSelect.options).map((o) => o.value).join(',');

        if (current !== wanted.map((c) => c.id).join(',')) {
            this.courseSelect.innerHTML = '';
            wanted.forEach((c) => {
                const option = document.createElement('option');
                option.value = c.id;
                option.textContent = c.name;
                this.courseSelect.appendChild(option);
            });
        }

        this.courseSelect.value = this.course;
    }

    /**
     * The count line under the heading. Scoped to the course, not to the status view.
     *
     * @return {Promise<string>}
     */
    summary() {
        const scoped = this.scoped();
        const todo = scoped.filter((t) => !this.isComplete(t));
        const overdue = todo.filter((t) => t.overdue);

        if (overdue.length > 0) {
            return getString('summaryoverdue', COMPONENT, {todo: todo.length, overdue: overdue.length});
        }

        return getString('summarydone', COMPONENT, {
            todo: todo.length,
            done: scoped.length - todo.length,
        });
    }

    /**
     * "1–5 of 9", collapsing to a single number when the page holds one item.
     *
     * @param {number} start Index of the first item on the page.
     * @param {number} total Items across all pages.
     * @param {number} size Page size.
     * @return {Promise<string>}
     */
    pageInfo(start, total, size) {
        const first = start + 1;
        const last = Math.min(total, start + size);

        if (first === last) {
            return getString('pagesingle', COMPONENT, {index: first, total: total});
        }

        return getString('pagerange', COMPONENT, {first: first, last: last, total: total});
    }

    /**
     * Accessible name for a pagination button.
     *
     * @param {string} which prev or next
     * @param {number} pageCount Total pages.
     * @return {Promise<string>}
     */
    pageLabel(which, pageCount) {
        const disabled = which === 'prev' ? this.page === 0 : this.page >= pageCount - 1;

        if (disabled) {
            return getString(`${which}pageunavailable`, COMPONENT);
        }

        return getString(`${which}page`, COMPONENT, {
            page: which === 'prev' ? this.page : this.page + 2,
            total: pageCount,
        });
    }

    /**
     * Empty state copy for the current view and course.
     *
     * @return {string}
     */
    emptyText() {
        const s = this.data.strings;

        if (this.view === 'done') {
            return s.emptydone;
        }
        if (this.view === 'all') {
            return s.emptyall;
        }

        return this.course === 'all' ? s.emptytodoall : s.emptytodocourse;
    }
}

/**
 * Start the block.
 *
 * Only the element id comes through js_call_amd. The task data is embedded in the page as
 * JSON, which is both the transport Moodle asks for at this size and one round trip fewer
 * than fetching it back over Ajax.
 *
 * @param {string} blockid Id of the block's card element.
 */
export const init = (blockid) => {
    const root = document.getElementById(blockid);
    const holder = root ? root.querySelector('[data-region="taskdata"]') : null;

    if (!holder) {
        return;
    }

    new Deadlines(root, JSON.parse(holder.textContent));
};
