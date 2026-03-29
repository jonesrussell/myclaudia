# AI-Powered Personal Operations Dashboard: UX Research Brief
**Date:** 2026-03-29
**Scope:** Proven UI/UX patterns for AI dashboards, daily planning tools, developer project tools, and commitment tracking

---

## 1. AI Chat Placement in Dashboard UIs

**The central question:** where does the AI assistant live?

Three proven patterns have emerged:

### a. Sidebar Panel (Persistent)
Used by Notion (2025 redesign). The sidebar gains a dedicated "AI chats" tab alongside pages, meetings, and notifications. AI is always one click away but never intrudes on the main content area. Best for tools where AI is a frequent companion but not the primary interface.

### b. Inline Overlay (Context-Sensitive)
Used by Notion and Grammarly AI. A floating prompt appears in the content area triggered by selection or keyboard shortcut. AI acts on what you are looking at. Best for editing and content tools.

### c. Command Palette (Keyboard-First)
The emerging preference for developer-centric tools. Universal shortcut (Cmd+K or similar) opens a search/action overlay. Zero persistent chrome, maximum focus on primary views. Works well when users are comfortable with keyboard-driven workflows.

### d. Dedicated Full View
Used by ChatGPT, Perplexity, and tools where AI is the primary interface. Not ideal for operational dashboards where structured views (calendar, tasks, issues) are also needed. Chat fades into background as agents work autonomously.

**Verdict for developer ops dashboards:** Command palette + sidebar panel is the winning combination. Sidebar for persistent access; command palette for quick invocation without leaving context.

---

## 2. Daily Planning / Morning Brief Patterns

Three philosophically distinct approaches, each with a proven user base:

### Sunsama: Guided Ritual (Manual + AI-Suggested)
- Structured daily planning ceremony at start of day
- AI suggests what to pull in from integrations (GitHub, email, calendar)
- User confirms and controls every decision — AI advises, human decides
- Best for: knowledge workers who want mindful, intentional planning
- UX: Sequential wizard-style flow, one decision at a time

### Morgen / Reclaim.ai: AI-Scheduled (Automated)
- Morgen uses "Frames" — named time blocks (Deep Work, Admin, Creative) with associated task categories and intensity levels
- AI Planner generates a daily schedule fitting tasks into Frames
- User adjusts the AI's initial plan rather than building from scratch
- Reclaim auto-defends recurring habits (focus time, lunch) against meetings
- Best for: users who want optimization, not ceremony
- UX: Timeline view with color-coded time blocks; AI suggestions are inline chips

### Akiflow / Morgen hybrid: Unified Inbox
- All tasks and events land in one inbox, then get scheduled
- Daily planning collapses into "process inbox + schedule items"
- UX: Two-pane layout — inbox left, timeline right

**Key pattern:** The "four-section daily brief" from Claryti (commitment tracking tool) is worth noting as a structure:
- **DO** — commitments you owe others (outbound)
- **RESPOND** — messages waiting for your reply
- **PREP** — context for today's meetings
- **CONNECT** — relationships that need attention

This maps directly to Claudriel's existing model (outbound commitments, follow-ups, events).

---

## 3. Multi-Project / Developer Context Switching

### Linear (2025 Dashboards)
- Modular, customizable dashboards with charts, tables, or single-number metrics
- Dashboard scope: workspace-wide OR filtered to a specific team
- Drill-through from dashboard metric to underlying issues
- Dashboards are shareable (public to workspace, team-scoped, or private)
- Each "team" in Linear functions as a project namespace with its own backlog, cycles, and settings

### GitHub Projects (2026)
- Hierarchy view now GA (March 2026) and default for new views
- Hierarchy view lets you see parent/child issue relationships across repos
- Views: Board, Table, Timeline (roadmap), Hierarchy
- Multi-repo projects supported via cross-repo issue linking

### Proven Sidebar Patterns for Multi-Project Nav
- Workspace/org switcher at top of sidebar (avatar-based, distinct visual identity per workspace)
- Project list below switcher, collapsible by category
- Active project highlighted, recent projects surfaced
- Sidebar width: 240-300px expanded, 48-64px collapsed (icon-only)
- Context-aware sidebar: changes available actions based on what you are viewing (file vs. issue vs. dashboard)

---

## 4. Chat + Dashboard Hybrid UIs

### Dust.tt Model
- AI agents as named team members with specific roles (not one generic "AI")
- Agents connect to internal knowledge sources (Slack, Google Drive, Notion, GitHub)
- Users interact via conversation but agents can take action, not just answer
- Positioned as: enterprise search (Glean) + agentic action (Dust)
- UI pattern: chat-centric with source citation sidebar; agents listed in a team-style roster

### Notion AI (2025 Redesign)
- Sidebar tabs: Pages | AI Chats | Meetings | Notifications
- AI chats treated as first-class content alongside documents
- Inline AI for editing (selection-triggered overlay)
- Search is AI-augmented: queries return semantic results, not just keyword matches

### Emerging Pattern: AI Assistant Cards
- Instead of chat bubbles, responses appear in structured cards with distinct visual treatment
- Cards can include: summary, action buttons, source citations, follow-up suggestions
- Better for operational contexts where AI output feeds into decisions (vs. pure conversation)

---

## 5. Commitment Tracking Across Tools

This is an emerging category with limited mature tooling. Key patterns found:

### Bi-Directional Tracking
- **Outbound:** commitments you made to others (you owe them)
- **Inbound:** commitments others made to you (they owe you / waiting on)
- Best tools automatically detect both directions from conversation content

### AI-Native Commitment Detection
- Claryti and similar tools parse meetings, email, and Slack for natural-language commitments ("I'll send that by Friday", "Can you review this?")
- No manual tagging required — extraction is automatic
- Confidence scoring determines what surfaces vs. what is silently captured

### Daily Brief Integration
- Overdue inbound items surface in the brief so you can follow up before delay becomes a problem
- Outbound items sorted by due date / relationship priority
- "Waiting on" as a distinct visual category from "to do"

### Gap in Market
No single tool handles commitment tracking cleanly across email + GitHub + chat. Most tools require manual task creation. Claudriel's automated extraction from Gmail is ahead of the market here.

---

## 6. Layout / Information Architecture Patterns That Work

### Three-Column Layout (proven for ops dashboards)
```
[Sidebar Nav] | [Primary Content / List] | [Detail Panel]
  120-180px         flex-fill                 320-400px
```
- Sidebar: navigation, workspace switcher, quick actions
- Primary: the current view (inbox, timeline, issue list, chat)
- Detail: selected item detail without losing list context

### Two-Pane with Toggle (simpler, mobile-friendly)
```
[Nav Sidebar] | [Content Area — switches between views]
```
- Top nav bar holds view switchers (Brief | Inbox | Calendar | Projects | Chat)
- Single content area swaps view on selection
- Simpler to build, less visual complexity

### Top-Level Navigation Patterns
- **Tab bar** (Linear, GitHub): horizontal tabs across top of content area for view switching within a project
- **Sidebar sections** (Notion, Linear): hierarchical nav with expandable sections
- **Command palette as primary** (Arc browser model): minimal chrome, everything via keyboard
- **Bottom nav** (mobile pattern, Amie iOS): large tap targets for primary views

### Visual Differentiation Strategies
- Color-coded time blocks (Morgen, Reclaim) for instant status at a glance
- Status chips / pills on list items (done, in progress, waiting)
- Relationship avatars on items (assigned to, mentioned, owed to)
- "Today" vs. "overdue" vs. "upcoming" temporal grouping in lists

---

## 7. Patterns Proven to NOT Work

- **Chat-only interfaces** for operational tools: users lose structured views they need for scanning
- **Aggressive AI suggestions**: surfacing too many suggestions interrupts flow (Sunsama deliberately avoids this)
- **Global unfiltered inboxes**: without grouping/filtering, multi-source inboxes become overwhelming
- **Volatile counts in summaries**: showing "9 open commitments" without linking to the underlying list breaks trust when the number is stale
- **Hidden AI**: AI buried in menus gets ignored; it needs to be one click or one shortcut away

---

## Sources

- [Where should AI sit in your UI? — UX Collective](https://uxdesign.cc/where-should-ai-sit-in-your-ui-1710a258390e)
- [Design Patterns For AI Interfaces — Smashing Magazine](https://www.smashingmagazine.com/2025/07/design-patterns-ai-interfaces/)
- [Linear Dashboards — Changelog](https://linear.app/changelog/2025-07-24-dashboards)
- [Comparing Conversational AI Tool UIs 2025 — IntuitionLabs](https://intuitionlabs.ai/articles/conversational-ai-ui-comparison-2025)
- [What Is an AI Planner? — Morgen](https://www.morgen.so/blog-posts/ai-planner)
- [Sunsama vs Morgen — Morgen](https://www.morgen.so/sunsama-vs-morgen)
- [Sunsama 2025 Roadmap](https://www.sunsama.com/blog/sunsama-2025-task-manager-roadmap)
- [What is Commitment Tracking? — Claryti](https://www.claryti.ai/blog/what-is-commitment-tracking)
- [Glean Alternative — Dust.tt](https://dust.tt/compare/glean)
- [Hierarchy View GA — GitHub Changelog](https://github.blog/changelog/2026-03-19-hierarchy-view-in-github-projects-is-now-generally-available/)
- [Amie Review 2025 — Skywork](https://skywork.ai/blog/amie-review-2025-calendar-tasks-ai-meeting-notes/)
- [Amie Review 2026 — ClickUp](https://clickup.com/blog/amie-calendar-review/)
- [Best Sidebar UX Practices 2025 — UIUXDesignTrends](https://uiuxdesigntrends.com/best-ux-practices-for-sidebar-menu-in-2025/)
- [AI Dashboard Design — Eleken](https://www.eleken.co/blog-posts/ai-dashboard-design)
- [6 Information Architecture Trends 2026 — Slickplan](https://slickplan.com/blog/information-architecture-trends)
