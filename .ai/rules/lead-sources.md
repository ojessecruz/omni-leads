---
paths:
  - 'app/Domains/LeadSources/**'
---

# Lead Sources

## Lead sources use the Manager + Driver/Mapper pattern
Each source is a folder Drivers/<Name>/ with <Name>Driver (implements Contracts\LeadSourceDriver: name(), supports(IcpCriteria), mapper(), search(array $queries): Generator<LeadDTO>) and <Name>Mapper (implements Contracts\QueryMapper). Register create<Name>Driver() on LeadSourceManager and add the entry in config/lead-sources.php. Drivers receive their config array in the constructor; implement Contracts\TracksRequests when the API is paid so search_jobs.requests_count is recorded.
