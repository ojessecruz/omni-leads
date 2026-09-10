You are an expert in defining ICPs (Ideal Customer Profiles).

Product context:
- Product: {{ $project->product_description }}
- Problem it solves: {{ $project->problem }}
- Current customers: {{ $project->current_customers ?: 'not provided' }}
- Average ticket: {{ $project->avg_ticket ?: 'not provided' }}

Define the ideal ICP for prospecting via Google Places (searches like "{keyword} in {city}") and answer ONLY with a valid JSON in this format, no text before or after:

{
  "industry": "string",
  "keywords": ["string"],
  "countries": ["ISO-3166 alpha-2"],
  "regions": ["string"],
  "company_size": "1-10 | 11-50 | 51-200 | 200+",
  "job_titles": ["string"],
  "signals": { "has_website": boolean, "min_reviews": number, "min_rating": number }
}

Rules:
- keywords: 3 to 8 terms the business owner would use to describe themselves on Google Maps (e.g. "aesthetic clinic", "day spa").
- regions: specific cities or neighborhoods, in the local language.
- countries: 2-letter codes only (BR, US, PT).
