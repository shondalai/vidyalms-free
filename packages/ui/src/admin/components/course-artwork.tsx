import { useState } from 'react';
import { BookOpen } from 'lucide-react';
import type { ManagedCourse } from '@vidyalms/api-sdk';
import { Icon } from '@/shared/components';

/** The same course identity in the library's list and card views. */
export function CourseArtwork({ course }: { course: ManagedCourse }) {
    const [failedImage, setFailedImage] = useState<string | null>(null);
    return (
        <span className={`vy-course-artwork vy-course-artwork-${course.id % 4}`} aria-hidden="true">
            {course.image && course.image !== failedImage ? (
                <img src={course.image} alt="" loading="lazy" referrerPolicy="no-referrer"
                    onError={() => setFailedImage(course.image)} />
            ) : <Icon icon={BookOpen} size={26} />}
        </span>
    );
}
