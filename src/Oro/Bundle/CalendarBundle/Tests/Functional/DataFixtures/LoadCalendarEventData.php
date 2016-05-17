<?php

namespace Oro\Bundle\CalendarBundle\Tests\Functional\DataFixtures;

use Doctrine\Common\DataFixtures\AbstractFixture;
use Doctrine\Common\Persistence\ObjectManager;

use Symfony\Component\Yaml\Yaml;

use Oro\Bundle\CalendarBundle\Entity\CalendarEvent;
use Oro\Bundle\CalendarBundle\Entity\Recurrence;

class LoadCalendarEventData extends AbstractFixture
{
    const CALENDAR_EVENT_TITLE = 'Regular event not in start:end range';

    /**
     * {@inheritdoc}
     */
    public function load(ObjectManager $manager)
    {
        $user = $manager->getRepository('OroUserBundle:User')->findOneByUsername('admin');
        $organization = $manager->getRepository('OroOrganizationBundle:Organization')->getFirst();
        $calendar = $manager->getRepository('OroCalendarBundle:Calendar')->findDefaultCalendar(
            $user->getId(),
            $organization->getId()
        );
        $filePath = __DIR__ . DIRECTORY_SEPARATOR . 'calendar_event_fixture.yml';
        $data = Yaml::parse($filePath);

        foreach ($data as $item) {
            $start = new \DateTime(gmdate(DATE_RFC3339, strtotime($item['start'])));
            $event = new CalendarEvent();
            $event->setCalendar($calendar)
                ->setTitle($item['title'])
                ->setStart($start)
                ->setEnd(
                    new \DateTime(gmdate(DATE_RFC3339, strtotime($item['end'])))
                )
                ->setAllDay($item['allDay']);
            if (!empty($item['recurrence'])) {
                $recurrence = new Recurrence();
                $recurrence->setRecurrenceType($item['recurrence']['type'])
                    ->setInterval($item['recurrence']['interval'])
                    ->setOccurrences($item['recurrence']['occurrences'])
                    ->setStartTime(
                        new \DateTime(gmdate(DATE_RFC3339, strtotime($item['recurrence']['startTime'])))
                    );
                $event->setRecurrence($recurrence);
            }
            if (!empty($item['exceptions'])) {
                foreach ($item['exceptions'] as $exceptionItem) {
                    $exception = new CalendarEvent();
                    $exception->setCalendar($calendar)
                        ->setTitle($exceptionItem['title'])
                        ->setStart(
                            new \DateTime(gmdate(DATE_RFC3339, strtotime($exceptionItem['start'])))
                        )
                        ->setEnd(
                            new \DateTime(gmdate(DATE_RFC3339, strtotime($exceptionItem['end'])))
                        )
                        ->setAllDay($exceptionItem['allDay'])
                        ->setIsCancelled($exceptionItem['isCancelled'])
                        ->setOriginalStart($start)
                        ->setRecurringEvent($event);
                    $event->addRecurringEventException($exception);
                }
            }
            $manager->persist($event);
            if (!empty($item['reference'])) {
                $this->setReference($item['reference'], $event);
            }
        }

        $manager->flush();
    }
}
