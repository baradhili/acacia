\documentclass[11pt, a4paper]{article}

% Basic packages for LuaLaTeX
\usepackage{fontspec}
\usepackage{geometry}
\usepackage{hyperref}
\usepackage{xcolor}
\usepackage{enumitem}
\usepackage{titlesec}

% ===== FONT CONFIGURATION =====
% Prefer Lato when installed; fall back to the engine's default.
\IfFontExistsTF{Lato Regular}{
    \setmainfont{Lato Regular}[
        BoldFont={Lato Bold},
        ItalicFont={Lato Italic},
        BoldItalicFont={Lato Bold Italic}
    ]
    \setsansfont{Lato Regular}[
        BoldFont={Lato Bold},
        ItalicFont={Lato Italic},
        BoldItalicFont={Lato Bold Italic}
    ]
}{}

\IfFontExistsTF{DejaVu Sans Mono}{
    \setmonofont{DejaVu Sans Mono}[Scale=0.9]
}{}

% Simple page layout
\geometry{left=2cm, right=2cm, top=2cm, bottom=2cm}

% Basic colors
\definecolor{heading}{RGB}{0, 0, 0}
\definecolor{meta}{RGB}{100, 100, 100}
\definecolor{linkcolor}{RGB}{0, 0, 200}

% Hyperlink setup
\hypersetup{
    colorlinks=true,
    linkcolor=linkcolor,
    urlcolor=linkcolor,
    pdfborder={0 0 0}
}

% Simple section formatting
\titleformat{\section}{\large\bfseries\color{heading}}{}{0em}{}[\titlerule]
\titleformat{\subsection}{\normalsize\bfseries}{}{0em}{}

% Custom list spacing
\setlist[itemize]{noitemsep, topsep=2pt, leftmargin=*}

\begin{document}

% ===== HEADER: Name & Contact =====
\begin{center}
    {\Huge\bfseries {!! $name ?? 'No Name' !!}}\\[0.5em]
    @if(!empty($label))
        \small\textit{ {!! $label !!} }\par\vspace{0.3em}
    @endif
    \small\color{meta}
    @if(!empty($email))\href{mailto:{!! $email !!}}{ {!! $email !!} }\quad @endif
    @if(!empty($phone)) {!! $phone !!} \quad @endif
    @if(!empty($location)) {!! $location !!} \quad @endif
    \par
    @if(!empty($links) && is_array($links))
        @foreach($links as $link)
            @if(!empty($link['url']))
                \href{ {!! $link['url'] !!} }{ {!! $link['label'] ?? $link['url'] !!} } \quad
            @endif
        @endforeach
    @endif
\end{center}

% ===== SUMMARY =====
@if(!empty($summary))
\section{Summary}
{!! $summary !!}
@endif

% ===== SKILLS =====
@if(!empty($skills) && is_array($skills))
\section{Skills}
\begin{itemize}
    @foreach($skills as $skill)
        \item \textbf{ {!! $skill['name'] ?? 'Unnamed' !!} }
        @if(!empty($skill['level']))
            \textit{({!! $skill['level'] !!})}
        @endif
        @if(!empty($skill['keywords']) && is_array($skill['keywords']))
            : {!! implode(', ', $skill['keywords']) !!}
        @endif
    @endforeach
\end{itemize}
@endif

% ===== WORK EXPERIENCE =====
@if(!empty($work) && is_array($work))
\section{Work Experience}
    @foreach($work as $job)
        \subsection*{ {!! $job['position'] ?? 'Unknown Position' !!} }
        \textit{ {!! $job['employer'] ?? 'Unknown Employer' !!} }
        @if(!empty($job['url']))
            \href{ {!! $job['url'] !!} }{[Link]}
        @endif
        @if(!empty($job['location'])) | {!! $job['location'] !!} @endif
        \hfill {!! $job['startDate'] ?? '' !!} -- {!! !empty($job['endDate']) ? $job['endDate'] : 'Present' !!}

        @if(!empty($job['description']))
        \par\small {!! $job['description'] !!}
        @endif

        @if(!empty($job['summary']))
        \par {!! $job['summary'] !!}
        @endif

        @if(!empty($job['highlights']) && is_array($job['highlights']))
        \begin{itemize}
            @foreach($job['highlights'] as $highlight)
                \item {!! $highlight !!}
            @endforeach
        \end{itemize}
        @endif

        @if(!empty($job['keywords']) && is_array($job['keywords']))
        \par\small\textcolor{meta}{\textbf{Keywords:} {!! implode(', ', $job['keywords']) !!} }
        @endif

        % ===== LINKED PROJECTS (displayed after work item content) =====
        @if(!empty($job['crossReferencedProjects']) && is_array($job['crossReferencedProjects']))
        \par\vspace{0.5em}
        \noindent\textbf{\color{meta}Related Projects:}
        \begin{itemize}
            @foreach($job['crossReferencedProjects'] as $ref)
                @php
                    $project = null;
                    foreach ($projects ?? [] as $proj) {
                        if (isset($proj['id']) && $proj['id'] == $ref) {
                            $project = $proj;
                            break;
                        }
                        if (isset($proj['name']) && $proj['name'] == $ref) {
                            $project = $proj;
                            break;
                        }
                    }
                @endphp

                @if($project)
                    \item
                    \textbf{ {!! $project['name'] ?? $ref !!} }
                    @if(!empty($project['url']))
                        \href{ {!! $project['url'] !!} }{[Link]}
                    @endif
                    @if(!empty($project['description']))
                    \par\small {!! $project['description'] !!}
                    @endif
                    @if(!empty($project['highlights']) && is_array($project['highlights']))
                    \begin{itemize}
                        @foreach($project['highlights'] as $highlight)
                            \item\small {!! $highlight !!}
                        @endforeach
                    \end{itemize}
                    @endif
                @else
                    \item \textcolor{meta}{[Referenced project not found: {!! $ref !!}]}
                @endif
            @endforeach
        \end{itemize}
        @endif

        \vspace{1em}
    @endforeach
@endif

% ===== VOLUNTEER EXPERIENCE =====
@if(!empty($volunteer) && is_array($volunteer))
\section{Volunteer Experience}
    @foreach($volunteer as $item)
        \subsection*{ {!! $item['position'] ?? 'Volunteer' !!} }
        \textit{ {!! $item['organization'] ?? '' !!} }
        \hfill {!! $item['startDate'] ?? '' !!} -- {!! !empty($item['endDate']) ? $item['endDate'] : 'Present' !!}

        @if(!empty($item['summary']))
        \par {!! $item['summary'] !!}
        @endif
        \vspace{1em}
    @endforeach
@endif

% ===== EDUCATION =====
@if(!empty($education) && is_array($education))
\section{Education}
    @foreach($education as $edu)
        \subsection*{
            @if(!empty($edu['programs']) && is_array($edu['programs']))
                @foreach($edu['programs'] as $idx => $prog)
                    {!! $prog['designation'] ?? '' !!} {!! $prog['name'] ?? '' !!}
                    @if(!empty($prog['concentration'])) ({!! $prog['concentration'] !!}) @endif
                    @if($idx < count($edu['programs']) - 1) , @endif
                @endforeach
            @else
                {!! $edu['area'] ?? '' !!}
            @endif
        }
        \textit{ {!! $edu['institution'] ?? 'Unknown Institution' !!} }
        @if(!empty($edu['subInstitution']))
            , {!! $edu['subInstitution'] !!}
        @endif
        @if(!empty($edu['location'])) | {!! $edu['location'] !!} @endif
        \hfill {!! $edu['startDate'] ?? '' !!} -- {!! !empty($edu['endDate']) ? $edu['endDate'] : 'Present' !!}

        @if(!empty($edu['gpa']))
        \par GPA: {!! $edu['gpa'] !!}
        @endif

        @if(!empty($edu['programs']) && is_array($edu['programs']))
            @foreach($edu['programs'] as $prog)
                @if(!empty($prog['gpa']))
                \par Program GPA: {!! $prog['gpa'] !!}
                @endif
                @if(!empty($prog['honors']))
                \par Honors: {!! $prog['honors'] !!}
                @endif
            @endforeach
        @endif

        @if(!empty($edu['awards']) && is_array($edu['awards']))
        \par\textbf{Awards:} {!! implode(', ', $edu['awards']) !!}
        @endif

        @if(!empty($edu['courses']) && is_array($edu['courses']))
        \par\textbf{Relevant Courses:}
        \begin{itemize}
            @foreach($edu['courses'] as $course)
                \item {!! $course !!}
            @endforeach
        \end{itemize}
        @endif

        @if(!empty($edu['extracurriculars']) && is_array($edu['extracurriculars']))
        \par\textbf{Extracurriculars:} {!! implode(', ', $edu['extracurriculars']) !!}
        @endif

        @if(!empty($edu['notes']))
        \par\small\textit{ {!! $edu['notes'] !!} }
        @endif
        \vspace{1em}
    @endforeach
@endif

% ===== AWARDS =====
@if(!empty($awards) && is_array($awards))
\section{Awards \& Honors}
\begin{itemize}
    @foreach($awards as $award)
        \item \textbf{ {!! $award['title'] ?? 'Unnamed Award' !!} }
        @if(!empty($award['awarder']))-- {!! $award['awarder'] !!} @endif
        @if(!empty($award['date']))({!! $award['date'] !!})@endif
        @if(!empty($award['summary']))\par {!! $award['summary'] !!} @endif
    @endforeach
\end{itemize}
@endif

% ===== CERTIFICATIONS =====
@if(!empty($certificates) && is_array($certificates))
\section{Certifications}
\begin{itemize}
    @foreach($certificates as $cert)
        \item \textbf{ {!! $cert['name'] ?? 'Unnamed' !!} }
        @if(!empty($cert['issuer']))({!! $cert['issuer'] !!}) @endif
        @if(!empty($cert['date']))-- {!! $cert['date'] !!} @endif
        @if(!empty($cert['id']))\textcolor{meta}{[ID: {!! $cert['id'] !!}]} @endif
        @if(!empty($cert['url']))\par \href{ {!! $cert['url'] !!} }{View Certificate} @endif
    @endforeach
\end{itemize}
@endif

% ===== PUBLICATIONS =====
@if(!empty($publications) && is_array($publications))
\section{Publications}
\begin{itemize}
    @foreach($publications as $pub)
        \item @if(!empty($pub['url']))\href{ {!! $pub['url'] !!} }{ {!! $pub['name'] ?? 'Untitled' !!} }@else {!! $pub['name'] ?? 'Untitled' !!} @endif
        @if(!empty($pub['publisher']))\textit{ {!! $pub['publisher'] !!} }@endif
        @if(!empty($pub['releaseDate']))({!! $pub['releaseDate'] !!})@endif
        @if(!empty($pub['summary']))\par {!! $pub['summary'] !!}@endif
    @endforeach
\end{itemize}
@endif

% ===== LANGUAGES =====
@if(!empty($languages) && is_array($languages))
\section{Languages}
\begin{itemize}
    @foreach($languages as $lang)
        \item {!! $lang['language'] ?? 'Unknown' !!}
        @if(!empty($lang['fluency']))\textit{({!! $lang['fluency'] !!})} @endif
    @endforeach
\end{itemize}
@endif

% ===== INTERESTS =====
@if(!empty($interests) && is_array($interests))
\section{Interests}
\begin{itemize}
    @foreach($interests as $interest)
        \item \textbf{ {!! $interest['name'] ?? 'Unnamed' !!} }
        @if(!empty($interest['keywords']) && is_array($interest['keywords']))
        : {!! implode(', ', $interest['keywords']) !!}
        @endif
    @endforeach
\end{itemize}
@endif

% ===== REFERENCES =====
@if(!empty($references) && is_array($references))
\section{References}
\begin{itemize}
    @foreach($references as $ref)
        \item \textbf{ {!! $ref['name'] ?? 'Unnamed' !!} }
        @if(!empty($ref['reference']))\par {!! $ref['reference'] !!} @endif
    @endforeach
\end{itemize}
@endif

% ===== FILTERED FOOTER NOTE =====
@if(!empty($isFiltered) && !empty($filterKeywords))
\vfill
\begin{center}
    \small\color{meta}This resume was filtered by keywords: {!! $filterKeywords !!} | Generated {!! $generatedAt ?? '' !!}
\end{center}
@endif

\end{document}
