# In-class Learning Engagement

A Moodle activity module designed to enhance in-class student engagement through real-time interactive quizzes, automatic question generation from lecture slides, and comprehensive analytics.

## Features

- **Slide Management**: Upload and manage lecture slides (PDF, PPT, PPTX).
- **Automatic Question Generation**: Utilizes NLP to automatically generate quiz questions from uploaded slide content.
- **Real-time Quiz Sessions**: Conduct live, interactive quiz sessions with students.
- **Clicker Integration**: Supports physical clicker devices via a REST API.
- **Analytics Dashboard**:
  - **Simple Analysis**: Real-time engagement metrics, participation rates, and comprehension checks.
  - **Advanced Analysis**: Concept difficulty tracking, response trends, and teaching recommendations.

## Installation

1. Download the plugin and extract it to `mod/classengage` in your Moodle installation.
2. Log in to your Moodle site as an administrator.
3. Go to **Site administration > Notifications** to trigger the database update.
4. Configure the plugin settings (NLP endpoint, API keys) in **Site administration > Plugins > Activity modules > In-class Learning Engagement**.

## Usage

1. **Add Activity**: Add "In-class Learning Engagement" to a course.
2. **Upload Slides**: Instructor uploads lecture slides.
3. **Generate Questions**: The system generates questions from the slides. Instructors can review, edit, and approve them.
4. **Start Session**: Instructor starts a live session.
5. **Student Participation**: Students join the session and answer questions in real-time.
6. **View Analytics**: Review detailed reports on student performance and engagement after the session.

## License

This project is licensed under the GNU General Public License v3 or later.
