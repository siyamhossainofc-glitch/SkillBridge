# SkillBridge Frontend Design Direction

## Three stylistic approaches

### Theme Name: Civic Blueprints
Very brief intro: A structured civic-tech interface with blueprint lines, warm paper surfaces, and confident cobalt accents. It makes complex marketplace workflows feel legible, trustworthy, and human.
Probability: 0.07

### Theme Name: Quiet Utility
Very brief intro: A calm, editorial productivity system built from soft neutrals, high-contrast type, and restrained utility panels. It emphasizes focus and dependable progress over visual spectacle.
Probability: 0.03

### Theme Name: Signal Garden
Very brief intro: A dark, luminous marketplace with electric signals, glowing status markers, and deep navy surfaces. It makes matching and notifications feel energetic and intelligent.
Probability: 0.08

## Chosen direction: Civic Blueprints

### Design Movement
Contemporary civic-tech editorial design, borrowing from municipal wayfinding systems, architectural drawing sets, and independent magazine layouts.

### Core Principles
1. Make trust visible through clear status, provenance, and progress cues.
2. Use asymmetric editorial composition instead of centered SaaS sameness.
3. Balance precision with warmth: paper-like surfaces, hairline rules, and one expressive accent.
4. Let every interaction expose the next useful action.

### Color Philosophy
A warm limestone background creates a grounded, tactile field; ink navy carries authority; cobalt blue marks action and navigation; a restrained marigold signals attention and opportunity. The palette should feel like a well-maintained public service rather than a generic startup dashboard.

### Layout Paradigm
A persistent left rail behaves like a drawing-set index while the main canvas uses an offset two-column composition: a wide primary work area and a narrow context rail. On mobile, the index collapses into a top bar and stacked panels.

### Signature Elements
1. Fine blueprint grid lines and numbered section markers.
2. Cobalt “ink stamps” for live states, with outlined status chips.
3. Editorial ruled cards with clipped corner notches rather than uniform rounded cards.

### Interaction Philosophy
Interactions should feel like navigating a well-labeled workspace: hover states reveal context, buttons use direct verbs, and state changes update a visible activity ledger. Empty states explain the next action rather than apologizing.

### Animation
Use short 160–220ms ease-out transitions. Cards enter with a subtle 8px upward motion and opacity shift; rail selection uses a sliding cobalt rule; numeric counters animate only on first load. Respect reduced motion and avoid decorative loops.

### Typography System
Use DM Sans for body and UI clarity, paired with Space Grotesk for display titles and section numerals. Display titles use tight tracking and strong weight; body copy stays 14–16px with generous line height. Uppercase labels use 0.12em tracking and cobalt ink.

### Brand Essence
SkillBridge is a trustworthy work-and-skill exchange for seekers and providers who need a clear path from opportunity to completed work—different because it makes every handoff visible.
Personality: grounded, capable, generous.

### Brand Voice
Headlines are direct and quietly optimistic. CTAs use active verbs. Microcopy names the current state and the next step without filler.
Example lines: “Good work starts with a clear brief.” / “Review the next best match.”

### Wordmark & Logo
A compact bridge mark made from two offset cobalt strokes meeting over a small marigold square, suggesting a span between need and capability. The symbol is used independently in the rail and favicon; the wordmark uses a custom-styled Space Grotesk lockup with an offset “B” crossbar.

### Signature Brand Color
Blueprint Cobalt — #2457D6. It is ownable, legible on limestone, and energetic enough to mark action without becoming neon.

## Style Decisions

- Every major surface carries at least one civic blueprint cue: rulework, clipped corner, numbered marker, ledger line, or cobalt stamp.
- The SkillBridge wordmark uses a custom Space Grotesk treatment with a visibly offset bridge crossbar; the bridge symbol remains legible at rail scale.
- Status language is written like a civic ledger: concise state label first, then the next useful action. Cobalt is reserved for action/navigation; marigold marks opportunity/attention.

## Implementation reminder
Ask of every choice: “Does this make the marketplace feel more trustworthy, more legible, or more human?”
