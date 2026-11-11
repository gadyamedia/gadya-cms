import { bindAll } from './editor/bind.js';
import { remember } from './editor/refresh.js';
import './editor/guard.js';
import './editor/inline-text.js';
import './editor/inline-panel.js';
import './editor/inline-image.js';
import './editor/inline-cards.js';
import './editor/inline-form.js';
import './editor/sections.js';
import './editor/links.js';
import './editor/publish.js';

/* Every module has said what it looks for; set up the page as it was served. */
remember(document);
bindAll(document);
