# Institutional Document Management System with AI Search
### Work Plan & Project Scope

---

## Project Overview

This project consists of the design and development of a custom WordPress plugin for institutional document management. The system enables organizations to publish, organize, and make their document catalog accessible to users through an intelligent, AI-powered search interface. The solution is built entirely within the WordPress ecosystem, requires no external services beyond an AI API, and is deployable on any standard WordPress installation.

---

## Objectives

- Provide administrators with a structured and efficient way to upload, categorize, and manage institutional documents.
- Deliver a public-facing, intelligent search experience that allows users to find documents using natural language in any language.
- Integrate Google Gemini AI to automate document metadata entry and power conversational search features.
- Ensure the system is fully configurable without touching code, through a dedicated settings panel.

---

## Scope of Work

### Module 1 — Document Management (Admin)

Development of a custom WordPress post type dedicated to institutional documents, including:

- Custom admin interface for creating and editing documents.
- File upload via the WordPress media library (PDF, Word, Excel, and other formats).
- Metadata fields: title, publication date, audience, document type, and configurable custom fields.
- Audience and Document Type taxonomies, manageable from the settings panel.
- Global custom fields system: administrators define fields once in settings and they apply to all documents automatically.

### Module 2 — AI-Assisted Metadata Entry (Admin)

Integration of Google Gemini AI within the document editor:

- Automatic PDF text extraction directly in the browser using PDF.js.
- "Process with AI" feature: the administrator selects which fields to populate, clicks a button, and the AI analyzes the document content and suggests values for each selected field.
- Supported fields include description, audience classification, and document type.
- All suggestions are editable before saving.

### Module 3 — Public Search Interface (Frontend)

A fully functional document search page, embedded anywhere via shortcode:

- Single-row filter bar: keyword input, Audience selector, Document Type selector, and Search button.
- Real-time autocomplete dropdown: as the user types, matching document titles appear as suggestions.
- AI-powered inline recommendations: after a brief typing pause, the system queries the AI, which analyzes the intent of the search and returns the most relevant document with an explanation — in the same language the user typed in.
- Clear button: resets the search and reloads the full document catalog.
- Results list with pagination (configurable, default 20 per page).

### Module 4 — Document Detail Modal

An interactive modal triggered by clicking any document card:

- **Details tab**: displays all document metadata including title, format tag, audience, document type, publication date, and all configured custom fields.
- **Preview tab**: embeds the PDF file directly in the browser for inline reading; non-PDF formats display an appropriate notice.
- **Ask AI tab**: a conversational chat interface allowing users to ask specific questions about the open document. The AI responds based on the document's metadata and content, in the user's language.
- Download button available at all times in the modal footer.

### Module 5 — AI Chat Bubble (Frontend)

A floating chat assistant accessible from any page where the shortcode is placed:

- Persistent button fixed to the bottom-right corner of the screen.
- Conversational interface: the user describes what they are looking for in natural language.
- The AI searches the document catalog, selects the most relevant result(s), and responds with an explanation and a clickable document card.
- Supports multi-turn conversation with context memory within the session.
- Responds in the same language the user writes in, regardless of the site's configured language.

### Module 6 — Settings Panel (Admin)

A tabbed administration panel with full control over all system parameters:

| Tab | Configuration |
|---|---|
| **General** | Menu name, menu icon (visual picker), archive URL slug |
| **AI** | Gemini API key, model selection, connection test |
| **Taxonomy** | Audience list, Document Type list (one per line) |
| **Custom Fields** | Add, reorder, and remove global document fields |
| **Shortcodes** | Reference with copy-to-clipboard examples |

### Module 7 — Shortcode System

All frontend components are embeddable via shortcode with configurable parameters:

```
[cirlot_document_search]
[cirlot_document_search show_ai="false" show_chat="false"]
[cirlot_document_search type="Policies" audience="Institution" per_page="10"]
```

Parameters control AI features, pre-selected filters, and results per page, allowing multiple instances on the same site with different configurations.

---

## Delivery Plan

### Phase 1 — Foundation
> Core infrastructure and document management

- Custom post type registration and admin interface
- File upload integration with WordPress media library
- Taxonomy system (Audience, Document Type)
- Custom fields architecture (global fields, settings-driven)
- Basic settings panel (General, Taxonomy tabs)

**Deliverable:** Functional admin panel to create, edit, and manage documents.

---

### Phase 2 — AI Integration (Admin)
> Intelligent metadata entry for editors

- Google Gemini API integration and settings (AI tab)
- PDF text extraction via PDF.js in the browser
- "Process with AI" feature in the document editor
- Field selection checklist and AI response handling
- Custom Fields settings tab

**Deliverable:** Editors can upload a PDF and auto-populate document fields using AI with a single click.

---

### Phase 3 — Public Search Interface
> Frontend document discovery

- Shortcode engine and rendering system
- Filter bar (keyword, audience, type, search button)
- Real-time autocomplete suggestions
- Paginated results list (document cards)
- Clear search and reload behavior

**Deliverable:** Embeddable search page with full filter and browse capability.

---

### Phase 4 — AI-Powered Search & Recommendations
> Intelligent search experience for end users

- AI inline recommendation triggered on user input
- Natural language understanding in any language
- Animated "thinking" state during AI processing
- AI suggestion card with document result and explanation
- Floating AI chat bubble (conversational document finder)
- Multi-turn chat with session history

**Deliverable:** Users can type a question in any language and receive an intelligent document recommendation with an explanation.

---

### Phase 5 — Document Detail Modal
> Rich document viewing experience

- Clickable document cards opening a detail modal
- Details tab: full metadata display
- Preview tab: inline PDF viewer (PDF.js / browser embed)
- Ask AI tab: document-specific conversational chat
- Download button and modal navigation

**Deliverable:** Users can read, preview, and query individual documents without leaving the page.

---

### Phase 6 — Final Configuration & Delivery
> Polishing, settings, and handoff

- General settings: menu name and icon picker
- Shortcodes reference tab with copy buttons
- UI polish and responsive adjustments
- Documentation (README)
- Final testing and delivery

**Deliverable:** Fully configured, documented, and production-ready system.

---

## Technical Stack

| Layer | Technology |
|---|---|
| Platform | WordPress (custom plugin) |
| Backend | PHP 8+, WordPress REST/AJAX API |
| Frontend | Vanilla JS, jQuery, CSS3 |
| AI | Google Gemini API (configurable model) |
| PDF Handling | PDF.js (client-side extraction and preview) |
| Storage | WordPress database + media library |

---

## Notes

- No third-party plugin dependencies — the entire system is self-contained.
- The AI API key is provided by the client (Google AI Studio, free tier available).
- The system is designed to run on any standard WordPress hosting environment.
- All AI features can be disabled independently via shortcode parameters, making the system usable without an AI key.
