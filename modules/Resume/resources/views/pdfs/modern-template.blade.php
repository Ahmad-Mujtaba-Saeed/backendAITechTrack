<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>{{ $resumeData['candidateName'][0]['firstName'] ?? '' }} {{ $resumeData['candidateName'][0]['familyName'] ?? '' }} - Resume</title>
<style>

@page {
    size: A4;
    margin: 0;
}

html, body {
    margin: 0;
    padding: 0;
    font-family: Arial, sans-serif;
    font-size: 13px;
    color: #333;
    background: #fff;
}


/* MAIN CV CONTAINER */
.main-table {
    width: 100%;
    max-width: 800px;
    margin: 0 auto;
    border-collapse: collapse;
    table-layout: fixed;
}


/* LEFT SIDEBAR */
.sidebar {
    width: 30%;
    background: #8B4444;
    color: #fff;
    vertical-align: top;
    padding: 25px;
    height: auto;       /* important - remove fixed height */
}


/* RIGHT CONTENT */
.content {
    width: 70%;
    background: #FAFAFA;
    padding: 25px;
    vertical-align: top;
}


/* SIDEBAR STYLES */

.sidebar .name {
    text-align: center;
    font-size: 22px;
    font-weight: bold;
    margin: 0 0 15px 0;
}


.profile-image {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    background: #D4B5C8;
    margin: 0 auto 15px auto;
    overflow: hidden;
}


.profile-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}


.sidebar-title {
    font-size: 14px;
    font-weight: bold;
    border-bottom: 2px solid rgba(255,255,255,0.3);
    padding-bottom: 5px;
    margin-top: 20px;
}


.personal-info p {
    margin: 5px 0;
    font-size: 12px;
}


/* CONTENT SECTIONS */

.section {
    margin-bottom: 15px;
    page-break-inside: avoid;
}


.section-title {
    color: #2C5F9E;
    font-size: 16px;
    font-weight: bold;
    border-bottom: 2px solid #2C5F9E;
    padding-bottom: 4px;
    margin-bottom: 10px;
}


.job-item,
.education-item {
    page-break-inside: avoid;
}


/* JOB DETAILS */

.job-title {
    font-size: 14px;
    font-weight: bold;
    margin: 0;
}


.job-date {
    font-size: 12px;
    color: #555;
}


.job-company {
    font-size: 13px;
    font-weight: 600;
    margin: 5px 0;
}


.job-description {
    font-size: 13px;
    line-height: 1.5;
    margin: 5px 0;
}


/* LISTS */

ul.achievements-list {
    margin: 5px 0 0 18px;
    padding: 0;
}


ul.achievements-list li {
    margin-bottom: 4px;
}


/* PDF PAGE CONTROL */

table {
    page-break-inside: auto;
}


tr {
    page-break-inside: avoid;
}


.section,
.profile-image,
.sidebar-title {
    page-break-inside: avoid;
}


</style>

</head>
<body>

  <table class="main-table">
    <tr>
      <!-- SIDEBAR -->
      <td class="sidebar">

        <h1 class="name">
          {{ $resumeData['candidateName'][0]['firstName'] ?? '' }}
          {{ $resumeData['candidateName'][0]['familyName'] ?? '' }}
        </h1>

        <div class="profile-image">
          @if(!empty($resumeData['profileImage']))
            <img src="{{ $resumeData['profileImage'] }}" alt="Profile Image">
          @endif
        </div>

        <div class="sidebar-section">
          <h2 class="sidebar-title">Personal details</h2>
          <div class="personal-info">
            @if(!empty($resumeData['email'][0])) <p>{{ $resumeData['email'][0] }}</p> @endif
            @if(!empty($resumeData['phoneNumber'][0]['formattedNumber'])) <p>{{ $resumeData['phoneNumber'][0]['formattedNumber'] }}</p> @endif
            @if(!empty($resumeData['location']))
              <p>{{ $resumeData['location']['city'] ?? '' }}<br>{{ $resumeData['location']['country'] ?? '' }}</p>
            @endif
          </div>
        </div>

        @if(!empty($resumeData['languages']))
        <div class="sidebar-section">
          <h2 class="sidebar-title">Languages</h2>
          <div class="personal-info">
            @foreach($resumeData['languages'] as $language)
              <p>{{ $language['name'] ?? '' }} {{ !empty($language['proficiency']) ? '(' . $language['proficiency'] . ')' : '' }}</p>
            @endforeach
          </div>
        </div>
        @endif

        @if(!empty($resumeData['hobbies']))
        <div class="sidebar-section">
          <h2 class="sidebar-title">Hobbies</h2>
          <div class="personal-info">
            @foreach($resumeData['hobbies'] as $hobby)
              <p>{{ $hobby }}</p>
            @endforeach
          </div>
        </div>
        @endif

      </td>

      <!-- CONTENT -->
      <td class="content">

        @if(!empty($resumeData['summary']['paragraph']))
        <div class="section">
          <h2 class="section-title">Professional Summary</h2>
          <p>{{ $resumeData['summary']['paragraph'] }}</p>
        </div>
        @endif

        @if(!empty($resumeData['workExperience']))
        <div class="section">
          <h2 class="section-title">Employment</h2>

          @foreach($resumeData['workExperience'] as $job)
          <div class="job-item">
            <table width="100%">
              <tr>
                <td><h3 class="job-title">{{ $job['workExperienceJobTitle'] ?? 'Position' }}</h3></td>
                <td align="right"><span class="job-date">
                  {{ $job['workExperienceDates']['start']['date'] ?? '' }}
                  @if(!empty($job['workExperienceDates']['end']['date']))
                    - {{ $job['workExperienceDates']['end']['date'] }}
                  @else
                    - Present
                  @endif
                </span></td>
              </tr>
            </table>
            @if(!empty($job['workExperienceOrganization']))
              <p class="job-company">
                {{ $job['workExperienceOrganization'] ?? '' }}
                @if(!empty($job['location'])), {{ $job['location'] }} @endif
              </p>
            @endif
            @if(!empty($job['workExperienceDescription']))
              <p class="job-description">{{ $job['workExperienceDescription'] }}</p>
            @endif
          </div>
          @endforeach
        </div>
        @endif

        @if(!empty($resumeData['education']))
        <div class="section">
          <h2 class="section-title">Education</h2>

          @foreach($resumeData['education'] as $edu)
          <div class="education-item">
            <table width="100%">
              <tr>
                <td><h3 class="job-title">{{ $edu['educationLevel']['label'] ?? $edu['educationAccreditation'] ?? 'Degree' }}</h3></td>
                <td align="right"><span class="job-date">
                  {{ $edu['educationDates']['start']['date'] ?? '' }}
                  @if(!empty($edu['educationDates']['end']['date']))
                    - {{ $edu['educationDates']['end']['date'] }}
                  @else
                    - Present
                  @endif
                </span></td>
              </tr>
            </table>
            @if(!empty($edu['educationOrganization']))
              <p class="job-company">
                {{ $edu['educationOrganization'] ?? '' }}
                @if(!empty($edu['location'])), {{ $edu['location'] }} @endif
              </p>
            @endif
          </div>
          @endforeach
        </div>
        @endif

      </td>
    </tr>
  </table>

</body>
</html>